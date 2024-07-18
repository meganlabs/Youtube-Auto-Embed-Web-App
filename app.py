import praw
import datetime
from googleapiclient.discovery import build
from flask import Flask, render_template

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

app = Flask(__name__)

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
            'url': f"https://www.youtube.com/watch?v={video['id']['videoId']}",
            'video_id': video['id']['videoId']
        }
    return None

def get_youtube_comments(video_id):
    youtube = build('youtube', 'v3', developerKey=YOUTUBE_API_KEY)
    request = youtube.commentThreads().list(
        part='snippet',
        videoId=video_id,
        maxResults=5,
        order='relevance'
    )
    response = request.execute()
    comments = []
    if 'items' in response and len(response['items']) > 0:
        for item in response['items']:
            comment = item['snippet']['topLevelComment']['snippet']
            comments.append({
                'author': comment['authorDisplayName'],
                'text': comment['textDisplay']
            })
    return comments

@app.route('/yt-embed/<video_id>')
def yt_embed(video_id):
    comments = get_youtube_comments(video_id)
    return render_template('video.html', video_id=video_id, comments=comments)

if __name__ == '__main__':
    app.run(debug=True)
