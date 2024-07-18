import datetime
from googleapiclient.discovery import build
from flask import Flask, render_template

# YouTube API key
YOUTUBE_API_KEY = 'AIzaSyCYPFUUZr3o_nID_YkCsxCroxySmFup6vk'

app = Flask(__name__)

def get_youtube_video_info(video_id):
    youtube = build('youtube', 'v3', developerKey=YOUTUBE_API_KEY)
    request = youtube.videos().list(
        part='snippet',
        id=video_id
    )
    response = request.execute()
    if 'items' in response and len(response['items']) > 0:
        video = response['items'][0]
        return {
            'title': video['snippet']['title'],
            'description': video['snippet']['description']
        }
    return None

def get_youtube_comments(video_id, max_results=15):
    youtube = build('youtube', 'v3', developerKey=YOUTUBE_API_KEY)
    request = youtube.commentThreads().list(
        part='snippet',
        videoId=video_id,
        maxResults=max_results,
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
    video_info = get_youtube_video_info(video_id)
    comments = get_youtube_comments(video_id, max_results=15)
    return render_template('video.html', video_id=video_id, video_title=video_info['title'], comments=comments)

if __name__ == '__main__':
    app.run(debug=True)

