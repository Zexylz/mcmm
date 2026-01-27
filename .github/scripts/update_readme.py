import os
import re
import subprocess

def get_tree():
    try:
        # Simple tree structure representation
        output = [".", "├── .github/ (scripts, workflows, linters)", "├── images/", "├── include/", "├── javascript/", "├── plugin/", "├── styles/"]
        
        # Add root level files (excluding dotfiles and node_modules)
        for f in os.listdir("."):
            if os.path.isfile(f) and not f.startswith(".") and f not in ["package-lock.json", "LICENSE", "build.sh"]:
                output.append(f"├── {f}")
        
        return "```\n" + "\n".join(output) + "\n```"
    except Exception as e:
        return f"Error generating tree: {e}"

def update_placeholder(content, placeholder, new_value):
    pattern = re.compile(
        rf"<!-- START_{placeholder} -->.*?<!-- END_{placeholder} -->",
        re.DOTALL
    )
    replacement = f"<!-- START_{placeholder} -->\n{new_value}\n<!-- END_{placeholder} -->"
    return re.sub(pattern, replacement, content)

def main():
    readme_path = "README.md"
    if not os.path.exists(readme_path):
        print("README.md not found")
        return

    with open(readme_path, "r", encoding="utf-8") as f:
        content = f.read()

    # 1. Update Tree
    tree_content = get_tree()
    content = update_placeholder(content, "TREE", tree_content)

    # 2. Update Release Notes (if release_notes.md exists)
    if os.path.exists("release_notes.md"):
        with open("release_notes.md", "r", encoding="utf-8") as f:
            release_notes = f.read()
            # Clean up the release notes if they have extra headers
            content = update_placeholder(content, "RELEASE", release_notes)

    with open(readme_path, "w", encoding="utf-8") as f:
        f.write(content)
    
    print("README.md updated successfully")

if __name__ == "__main__":
    main()
