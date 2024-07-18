import praw
import datetime
from googleapiclient.discovery import build

# Reddit credentials
reddit = praw.Reddit(
    client_id='yBt2eaNiAEJqLMCsiJW9-w',
    client_secret='yGDR5CeuQ2ley2kiI6-FXs_U6lEYLw',
    username='MiserableNothing4078',
    password='Password@123',
    user_agent='comment-bot by MiserableNothing4078'
)

# YouTube API key
YOUTUBE_API_KEY = 'AIzaSyCYPFUUZr3o_nID_YkCsxCroxySmFup6vk'

def get_top_reddit_post(subreddit_name):
    subreddit = reddit.subreddit(subreddit_name)
    for submission in subreddit.hot(limit=10):
        if not submission.stickied:
            return submission.title
    return None

def search_youtube_video(query):
    youtube = build('youtube', 'v3', developerKey=YOUTUBE_API_KEY)
    one_week_ago = (datetime.datetime.utcnow() - datetime.timedelta(days=7)).isoformat("T") + "Z"
    request = youtube.search().list(
        part='snippet',
        q=query,
        type='video',
        order='date',
        publishedAfter=one_week_ago,
        maxResults=1
    )
    response = request.execute()
    if 'items' in response and len(response['items']) > 0:
        video = response['items'][0]
        return {
            'title': video['snippet']['title'],
            'url': f"https://www.youtube.com/watch?v={video['id']['videoId']}"
        }
    return None

def save_to_file(video_info, filename='youtube_video.txt'):
    with open(filename, 'w') as file:
        file.write(f"Title: {video_info['title']}\n")
        file.write(f"URL: {video_info['url']}\n")

def main():
    subreddit_name = 'news'
    top_post_title = get_top_reddit_post(subreddit_name)
    
    if top_post_title:
        print(f"Top post title: {top_post_title}")
        video_info = search_youtube_video(top_post_title)
        
        if video_info:
            print(f"Found video: {video_info['title']} - {video_info['url']}")
            save_to_file(video_info)
        else:
            print("No related video found.")
    else:
        print("No top post found.")

if __name__ == '__main__':
    main()
