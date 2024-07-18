import datetime
from googleapiclient.discovery import build
from flask import Flask, render_template
import html

# YouTube API key
YOUTUBE_API_KEY = 'AIzaSyCYPFUUZr3o_nID_YkCsxCroxySmFup6vk'

app = Flask(__name__)

def get_youtube_comments(video_id):
    youtube = build('youtube', 'v3', developerKey=YOUTUBE_API_KEY)
    request = youtube.commentThreads().list(
        part='snippet',
        videoId=video_id,
        maxResults=15,
        order='relevance'
    )
    response = request.execute()
    comments = []
    if 'items' in response and len(response['items']) > 0:
        for item in response['items']:
            comment = item['snippet']['topLevelComment']['snippet']
            comments.append({
                'author': html.unescape(comment['authorDisplayName']),
                'text': html.unescape(comment['textDisplay'])
            })
    return comments

@app.route('/yt-embed/<video_id>')
def yt_embed(video_id):
    comments = get_youtube_comments(video_id)
    return render_template('video.html', video_id=video_id, comments=comments)

if __name__ == '__main__':
    app.run(debug=True)
