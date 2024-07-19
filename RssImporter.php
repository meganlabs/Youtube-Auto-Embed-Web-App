<?php

namespace spark\drivers\Http;

use Rct567\DomQuery\DomQuery;
use SimplePie;
use SimplePie_Item;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;
use spark\drivers\Filter\Xss;
use spark\models\FeedModel;
use spark\models\PostModel;

/**
* RssImporter
*/
class RssImporter
{
    public const YT_NAMESPACE = 'http://www.youtube.com/xml/schemas/2015';

    public const SZ_NAMESPACE = 'https://www.seznam.cz';

    protected $trustedIframeDomains = [];

    public function __construct()
    {
        $domains = explode(
            "\n",
            get_option(
                'iframe_allowed_domains',
                "youtube.com\nyoutu.be\nplayer.twitch.tv\nplayer.vimeo.com\ndailymotion.com"
            )
        );

        $this->trustedIframeDomains = $domains;
    }

    /**
     * Fetches posts and return formatted array
     *
     * @param  string $feedItem The feed item from FeedModel
     * @return array
     */
    public function fetchPosts(array $feedItem, $lastChecked = null)
    {
        $feed = new SimplePie();
        $feed->set_feed_url($feedItem['feed_url']);
        // Supress certificate errors
        $feed->set_curl_options([
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        // sometime's it's the only way
        $feed->force_feed(true);

        $feed->enable_order_by_date(true);


        $strip_htmltags = $feed->strip_htmltags;
        // We gonna validate the iframes later
        $strip_htmltags = array_remove_value($strip_htmltags, 'iframe');
        $feed->strip_htmltags($strip_htmltags);

        // Why we do this?
        // Because some feeds blocks the default simplepie parser useragent
        // Also to fetch fulltext, we need to make sure the article renders as it would on a normal browser
        $feed->set_useragent('Mozilla/5.0 (Windows NT 6.3; WOW64) ' .
            'AppleWebKit/537.36 (KHTML, like Gecko) ' .
            'Chrome/60.0.2214.115 Safari/537.36');

        // We don't need caching, since we will save the posts in Db
        $feed->enable_cache(false);
        $feed->init();

        $posts = [];
        $xss = new Xss();
        // Risky? I know, will sanitize manually later
        if (!empty($this->trustedIframeDomains)) {
            $xss->addAllowedTags(['iframe']);
        }

        $http = Http::getSession();

        // Support for limited number of posts retrieval
        $maxItems = (int) $feedItem['feed_max_items'];

        $feedItems = $feed->get_items();

        if ($maxItems > 0) {
            $feedItems = $feed->get_items(0, $maxItems);
        }

        // Filters @since 1.0.5
        $keywordMode = (int) $feedItem['feed_keyword_mode'];

        $requiredKeywords = explode(',', $feedItem['feed_required_keywords']);
        $requiredKeywords = array_map('trim', $requiredKeywords);

        $minContentLength = (int) $feedItem['feed_required_content_length'];
        $contentMaxLength = (int) $feedItem['feed_content_maxlength'];

        foreach ($feedItems as $item) {
            $post = self::getPostFormat();
            $post['post_pubdate'] = $item->get_date('U');

            // If timestamps are in the future they should be listed as current timestamp
            if ($post['post_pubdate'] > time()) {
                $post['post_pubdate'] = time();
            }

            if (!$post['post_pubdate']) {
                $post['post_pubdate'] = time();
            }

            // If we checked earlier we should've found this post
            // so skip if the last refreshed time is higher than the post date
            if ($feedItem['feed_last_refreshed'] > $post['post_pubdate']) {
                continue;
            }


            // Some feed have the title already html escaped this will cause problem in the views
            // So we decode the entities and will escape them on view
            $post['post_title'] = html_entity_decode($item->get_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $post['post_title'] = sp_strip_tags($post['post_title'], true);
            $post['post_excerpt'] = limit_words(sp_strip_tags($item->get_description(), true));
            $post['post_source'] = trim((string)$item->get_link());
            $post['post_author'] = trim((string)$item->get_author());

            if (empty($post['post_author'])) {
                $post['post_author'] = 'Anonymous';
            }

            // By default fallback to description
            $post['post_content'] = $item->get_content();

            // Convert to string to prevent null value
            $post['post_featured_image'] = $this->getFeaturedImage($item);

            // If full text is enabled, try to fetch that
            if ((int) $feedItem['feed_fetch_fulltext']) {
                try {
                    $response = $http->get($item->get_link());

                    $body = $response->body;
                    if (!empty($feedItem['feed_fulltext_selector'])) {
                        $this->fetchFromSelector($body, $item, $feedItem, $post);
                    } else {
                        $this->autoFullText($body, $item, $feedItem, $post);
                    }
                } catch (\Exception $e) {
                    // Log every error damn it
                    // @since v1.0.5
                    logger()->error($e);
                }
            }

            // Last attempt at finding featured image
            if (empty($post['post_featured_image'])) {
                preg_match('/<img.+src=[\'"]([^\'"]+)[\'"].*>/', (string)$post['post_content'], $m);

                if (isset($m[1])) {
                    $ext = trim(pathinfo($m[1], PATHINFO_EXTENSION));
                    if (!empty($ext)) {
                        $post['post_featured_image'] = trim($m[1]);
                    }
                }
            }

            // Sometimes images do matter
            if ((int) $feedItem['feed_ignore_without_image'] && empty($post['post_featured_image'])) {
                continue;
            }

            // Clean the post body
            $post['post_content'] = $xss->filter($post['post_content']);

            // Make sure keyword filter is enabled and keywords aren't empty
            if ($keywordMode && !empty($requiredKeywords)) {
                $matches = $this->matchesRequiredKeywords($post, $requiredKeywords);

                // Enabled in require mode and doesn't match, so ignore this post
                if ($keywordMode === FeedModel::KEYWORD_FILTER_FOLLOW && !$matches) {
                    continue;
                }

                // Enabled to ignore post if matches these keywords
                if ($keywordMode === FeedModel::KEYWORD_FILTER_IGNORE && $matches) {
                    continue;
                }
            }

            // Fix. utilize the post content based ignore variable: $minContentLength
            if ($minContentLength > 0 && mb_strlen($post['post_content']) < $minContentLength) {
                continue;
            }


            // Fix. utilize the post content limit variable
            if ($contentMaxLength > 0) {
                $post['post_content'] = limit_string($post['post_content'], $contentMaxLength);
            }


            // Added support for importing domain based iframes
            // @since 1.0.5
            if (!empty($this->trustedIframeDomains)) {
                $post['post_content'] = preg_replace_callback('%(?:<iframe[^>]*)(?:(?:\/>)|(?:>.*?<\/iframe>))%sim', [$this, 'sanitizeFrames'], $post['post_content']);
            }


            // Added support for YouTube embed for native YouTube Feeds
            $yt = $item->get_item_tags(static::YT_NAMESPACE, 'videoId');

            if (isset($yt[0]['data'])) {
                $medias = $item->get_item_tags(SIMPLEPIE_NAMESPACE_MEDIARSS, 'group');

                if (isset($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['description'][0]['data'])) {
                    $post['post_content'] = $xss->filter($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['description'][0]['data']);
                }

                $post['post_content'] = $this->getYTEmbedCode($yt[0]['data']) . '<br>'  . $post['post_content'];
            }

            if (empty($post['post_source']) || is_null($post['post_source'])) {
                $post['post_source'] = 'n/a';
            }

            // Fix invalid ampersands
            $post['post_featured_image'] = str_replace(
                '&amp;',
                '&',
                $post['post_featured_image']
            );

            $post['post_source'] = str_replace(
                '&amp;',
                '&',
                $post['post_source']
            );

            $post = apply_filters('post_imported_after', $post, $feedItem);

            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * Attempts to fetch the featured image from the item
     *
     * @param  SimplePie_Item $item
     * @return string
     */
    protected function getFeaturedImage(SimplePie_Item $item)
    {
        // First of all handle YouTube feeds
        $yt = $item->get_item_tags(static::YT_NAMESPACE, 'videoId');

        if (isset($yt[0]['data'])) {
            return "https://i1.ytimg.com/vi/{$yt[0]['data']}/maxresdefault.jpg";
        }

        $thumb = $item->get_item_tags('', 'thumbimage');

        // Are we looking at a direct image url?
        if (isset($thumb[0]['data']) && !empty(trim((string)$thumb[0]['data']))) {
            return $thumb[0]['data'];
        }


        $image = $item->get_item_tags('', 'image');

        // Are we looking at a direct image url?
        if (isset($image[0]['data']) && !empty(trim((string)$image[0]['data']))) {
            return $image[0]['data'];
        }

        // So, we have an image child then
        if (isset($image[0]['child']['']['url'][0]['data']) && !empty(trim((string)$image[0]['child']['']['url'][0]['data']))) {
            return $image[0]['child']['']['url'][0]['data'];
        }

        // Yahoo Media NS
        $medias = $item->get_item_tags(SIMPLEPIE_NAMESPACE_MEDIARSS, 'group');

        if (isset($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['content']) && is_array($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['content'])) {
            foreach ($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['content'] as $key => $content) {
                // No URLs means no business
                if (!isset($content['attribs']['']['url'])) {
                    continue;
                }

                // Make sure we only use image mime type not video or flash
                if (isset($content['attribs']['']['type']) &&
                    strpos($content['attribs']['']['type'], 'image') !== false) {
                } else {
                    continue;
                }

                // If the isDefault is set (mostly found in feedburner) return that
                if (isset($content['attribs']['']['isDefault']) && strtolower($content['attribs']['']['isDefault']) == 'true') {
                    return $content['attribs']['']['url'];
                }


                // Fallback to the URL
                return $content['attribs']['']['url'];
            }
        }

        // Hotfix for YouTube channel feeds and other feeds that use media:group and a media:thumbnail tag inside
        if (isset($medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['thumbnail'][0]['attribs']['']['url'])) {
            return $medias[0]['child'][SIMPLEPIE_NAMESPACE_MEDIARSS]['thumbnail'][0]['attribs']['']['url'];
        }

        // Support for multiple enclosures added
        $enclosures = $item->get_enclosures();

        if (is_array($enclosures)) {
            foreach ($enclosures as $enclosure) {
                // We found an image, tada
                if (strpos((string)$enclosure->get_type(), 'image') !== false ||
                    $enclosure->medium === 'image'
                ) {
                    return $enclosure->get_link();
                }
            }
        }

        // Fallback for a bug where get_encloures() wont even return all the enclosures
        // so in case we dont miss out our only one enclosure
        // put this here just in case
        // We found an image, tada
        if (strpos((string)$enclosure->get_type(), 'image') !== false ||
            $enclosure->medium === 'image'
        ) {
            return $enclosure->get_link();
        }

        // Try to fetch the featured image via the image tag
        $image = $item->get_item_tags('', 'image');

        if (isset($image[0]['child']['']['url'][0]['data'])) {
            return $image[0]['child']['']['url'][0]['data'];
        }


        $bigImage = $item->get_item_tags(static::SZ_NAMESPACE, 'bigImage');

        if (isset($bigImage[0]['child'][static::SZ_NAMESPACE]['url'][0]['data'])) {
            return $bigImage[0]['child'][static::SZ_NAMESPACE]['url'][0]['data'];
        }


        if (isset($bigImage[0]['data'])) {
            return $bigImage[0]['data'];
        }


        // Non standard Image tag as seen in: https://www.extra.cz/rss.xml
        $sz = $item->get_item_tags(static::SZ_NAMESPACE, 'image');

        if (isset($sz[0]['child'][static::SZ_NAMESPACE]['url'][0]['data'])) {
            return $sz[0]['child'][static::SZ_NAMESPACE]['url'][0]['data'];
        }


        if (isset($sz[0]['data'])) {
            return $sz[0]['data'];
        }


        // Look for an storyImage tag, found in feedburner
        $storyImage = $item->get_item_tags('', 'StoryImage');

        if (isset($storyImage[0]['data']) && !empty(trim((string)$storyImage[0]['data']))) {
            return $storyImage[0]['data'];
        }

        // Look for an fullimage tag, found in feedburner
        $fullimage = $item->get_item_tags('', 'fullimage');

        if (isset($fullimage[0]['data']) && !empty(trim((string)$fullimage[0]['data']))) {
            return $fullimage[0]['data'];
        }

        // If we're lucky we might get a thumbnail tag, but they're last resort since they tend to be small
        $thumbnail = $item->get_thumbnail();

        if (!empty($thumbnail['url'])) {
            return $thumbnail['url'];
        }

        return '';
    }

    /**
     * Fetch full text from selector
     *
     * @param  string $body
     * @param  SimplePie_Item $item
     * @param  array  $feedItem
     * @param  array  $post
     *
     * @return
     */
    protected function fetchFromSelector($body, $item, array &$feedItem, array &$post)
    {
        // Fuck off script tags
        $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
        // You too inline style
        $body = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $body);

        $dom      = new DomQuery();

        libxml_disable_entity_loader(true);
        libxml_use_internal_errors(true);

        $dom_document = new \DOMDocument('1.0', 'UTF-8');

        // Fix encoding issues
        $encodingFixedBody = @mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8');

        if (!empty($encodingFixedBody)) {
            $body = $encodingFixedBody;
        }

        $dom_document->loadHTML($body);
        $dom_document->strictErrorChecking = false;
        $dom_document->validateOnParse = false;
        $dom_document->recover = true;

        $dom->setDomDocument($dom_document);


        $query = $dom->find($feedItem['feed_fulltext_selector']);

        $html = null;

        foreach ($query as $node) {
            $tagName = $node->tagName;

            $class = $node->attr('class');

            // Wrap the element in the selector
            if ($tagName) {
                $html .= '<' . $tagName . ' class="' . e_attr($class) . '">';
            }

            $html .= $node->html();

            // Close the selector
            if ($tagName) {
                $html .= "</{$tagName}>";
            }
        }

        if (!empty($html)) {
            $post['post_content'] = $html;
        }
    }

    /**
     * Auto full text via Readbility.php
     *
     * @param  string $html
     * @param  SimplePie_Item $item
     * @param  array  $feedItem
     * @param  array  $post
     *
     * @return Readability
     */
    protected function autoFullText($html, $item, array &$feedItem, array &$post)
    {
        $config = new Configuration([
            'fixRelativeURLs' => true,
            'originalURL'     => $item->get_link(),
            'SummonCthulhu'   => true,
            'NormalizeEntities' => true,
        ]);

        $readability = new Readability($config);

        try {
            $readability->parse($html);
        } catch (ParseException $e) {
            return false;
        }


        $post['post_content'] = $readability->getContent();

        // If present use the high quality image URL for better thumbnails
        if (!empty($readability->getImage())) {
            $post['post_featured_image'] = $readability->getImage();
        }

        return $readability;
    }

    /**
     * Returns if post matches the required keywords or not
     * @param  array  $post
     * @param  array  $requiredKeywords
     * @return boolean
     */
    public function matchesRequiredKeywords(array $post, array $requiredKeywords)
    {
        $count = count($requiredKeywords) - 1;
        $regex = '';

        foreach ($requiredKeywords as $key => $keyword) {
            $regex .= preg_quote($keyword);
            if ($count !== $key) {
                $regex .= '|';
            }
        }

        $string = "{$post['post_title']} {$post['post_content']}";

        return (bool) preg_match("/{$regex}/imu", $string);
    }

    public function sanitizeFrames($body)
    {
        $src = DomQuery::create($body[0])->attr('src');

        // Na-ah you don't
        if (!filter_var($src, FILTER_VALIDATE_URL)) {
            return '';
        }

        $host = parse_url($src, PHP_URL_HOST);

        // Remove www. for convinience
        $host = preg_replace('/www\./', '', $host, 1);

        // Sorry, trusted only
        if (!in_array($host, $this->trustedIframeDomains, true)) {
            return '';
        }

        // Alright, you are trusted, but we will still escape
        $src = e_attr($src);

        return $this->getEmbedCode($src);
    }

    public function getYTEmbedCode($id, array $attrs = [])
    {
        return $this->getEmbedCode("https://www.youtube.com/embed/{$id}?autoplay=1", $attrs);
    }

    public function getEmbedCode($url, array $attrs = [])
    {
        $attribs = '';

        foreach ($attrs as $key => $value) {
            $attribs .= ' ' . e_attr($key) . '="' . e_attr($value) . '"';
        }
        return '<p class="embed-responsive embed-responsive-16by9 post-embed"><iframe class="embed-responsive-item" src="' . e_attr($url) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen'. $attribs .'></iframe></p>';
    }

    public static function getPostFormat()
    {
        return [
            'post_title' => '',
            'post_content' => '',
            'post_featured_image' => '',
            'post_excerpt' => '',
            'post_source' => '',
            'post_pubdate' => 0,
            'post_type' => PostModel::TYPE_IMPORTED
        ];
    }
}
