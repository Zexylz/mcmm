import os
import subprocess
import sys
import time
from google import genai

def get_latest_tag():
    try:
        tags = subprocess.check_output(["git", "tag", "--sort=-creatordate"]).decode("utf-8").split()
        return tags[0] if tags else None
    except Exception as e:
        print(f"Error getting latest tag: {e}")
        return None

def get_previous_tag(current_tag):
    try:
        tags = subprocess.check_output(["git", "tag", "--sort=-creatordate"]).decode("utf-8").split()
        if current_tag in tags:
            idx = tags.index(current_tag)
            if idx + 1 < len(tags):
                return tags[idx + 1]
        return None
    except Exception:
        return None

def get_commit_log(from_tag, to_tag):
    try:
        if from_tag:
            cmd = ["git", "log", f"{from_tag}..{to_tag}", "--pretty=format:%s"]
        else:
            cmd = ["git", "log", "-n", "20", "--pretty=format:%s"]
        
        return subprocess.check_output(cmd).decode("utf-8")
    except Exception as e:
        print(f"Error getting commit log: {e}")
        return ""

def generate_notes(current_tag, commits, api_key):
    try:
        client = genai.Client(api_key=api_key)
        
        prompt = f"""
        You are a release note generator for version {current_tag}. 
        Below is a list of commit messages for this release.
        Please summarize them into a beautiful, human-readable release note markdown.
        Group them into sections like:
        - 🚀 New Features
        - 🛠 Improvements & Refinement
        - 🐛 Bug Fixes
        
        IMPORTANT: Use only "{current_tag}" as the version number in your text.
        Keep it concise and professional.
        
        Commits:
        {commits}
        """
        
        models_to_try = [
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite'
        ]
        
        last_error = None
        for model_name in models_to_try:
            try:
                print(f"Trying Gemini model: {model_name}...")
                response = client.models.generate_content(
                    model=model_name,
                    contents=prompt
                )
                return response.text.strip()
            except Exception as e:
                print(f"Model {model_name} failed: {e}")
                last_error = e
                if "429" in str(e):
                    time.sleep(5) # Small wait if rate limited
                continue
        
        raise last_error
    except Exception as e:
        print(f"Error calling Gemini: {e}")
        return f"Release notes could not be generated automatically (AI Error: {e}).\n\nChanges:\n{commits}"

def main():
    api_key = os.environ.get("GEMINI_API_KEY")
    current_tag = os.environ.get("GITHUB_REF_NAME")
    
    if not api_key:
        print("Error: GEMINI_API_KEY not set.")
        sys.exit(1)
        
    prev_tag = get_previous_tag(current_tag)
    print(f"Generating notes from {prev_tag or 'beginning'} to {current_tag}")
    
    commits = get_commit_log(prev_tag, current_tag)
    if not commits:
        print("No commits found between tags.")
        notes = f"Release {current_tag}\n\nNo changes recorded."
    else:
        notes = generate_notes(current_tag, commits, api_key)
        
    with open("release_notes.md", "w", encoding="utf-8") as f:
        f.write(notes)
    
    print("Release notes generated to release_notes.md")

if __name__ == "__main__":
    main()
