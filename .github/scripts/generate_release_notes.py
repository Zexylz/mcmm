import os
import subprocess
import sys
import google.generativeai as genai

def get_latest_tag():
    try:
        # Get all tags sorted by creation date
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
            # If no previous tag, get last 20 commits
            cmd = ["git", "log", "-n", "20", "--pretty=format:%s"]
        
        return subprocess.check_output(cmd).decode("utf-8")
    except Exception as e:
        print(f"Error getting commit log: {e}")
        return ""

def generate_notes(commits, api_key):
    try:
        genai.configure(api_key=api_key)
        
        prompt = f"""
        You are a release note generator. Below is a list of commit messages for a new release.
        Please summarize them into a beautiful, human-readable release note markdown.
        Group them into sections like:
        - 🚀 New Features
        - 🛠 Improvements & Refinement
        - 🐛 Bug Fixes
        
        Keep it concise and professional.
        
        Commits:
        {commits}
        """
        
        # Try different model versions as fallback
        models_to_try = [
            'gemini-1.5-flash', 
            'gemini-1.5-flash-latest', 
            'gemini-2.0-flash-exp', 
            'gemini-1.5-pro'
        ]
        
        last_error = None
        for model_name in models_to_try:
            try:
                print(f"Trying Gemini model: {model_name}...")
                model = genai.GenerativeModel(model_name)
                response = model.generate_content(prompt)
                return response.text.strip()
            except Exception as e:
                print(f"Model {model_name} failed: {e}")
                last_error = e
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
        notes = "No changes recorded."
    else:
        notes = generate_notes(commits, api_key)
        
    # Write to a file for GitHub Actions to read
    with open("release_notes.md", "w", encoding="utf-8") as f:
        f.write(notes)
    
    print("Release notes generated to release_notes.md")

if __name__ == "__main__":
    main()
