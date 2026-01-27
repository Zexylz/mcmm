import os
import sys
import json
import argparse
from openai import OpenAI

def fix_file(file_path, errors, api_key):
    """
    Sends the file content and errors to OpenAI to generate a fixed version.
    """
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception as e:
        print(f"Error reading file {file_path}: {e}")
        return False

    client = OpenAI(api_key=api_key)

    prompt = f"""
You are an expert code fixer and linter.
I have a file (which may be mixed PHP/HTML/JS/CSS).
Automated linters have found the following errors in this file (or in extracted parts of it):

{json.dumps(errors, indent=2)}

Please fix these errors in the provided file content.
IMPORTANT RULES:
1. Output ONLY the full, corrected file content. No markdown code blocks, no explanations.
2. Preserve all PHP tags (`<?php ... ?>`), HTML structure, and logic exactly. Only fix the style/lint issues.
3. Keep the same indentation style.
4. If the error refers to a line number, it should vaguely correspond to the line in this file (as extraction was line-preserving), but use your judgement to find the context.

File Content:
{content}
"""

    try:
        response = client.chat.completions.create(
            model="gpt-4o", # Or gpt-4-turbo
            messages=[
                {"role": "system", "content": "You are a helpful coding assistant that outputs only raw code."},
                {"role": "user", "content": prompt}
            ],
            temperature=0.1
        )
        
        fixed_content = response.choices[0].message.content.strip()
        
        # Basic sanity check: remove markdown code blocks
        if fixed_content.startswith("```"):
            fixed_content = fixed_content.split("\n", 1)[1]
        if fixed_content.endswith("```"):
            fixed_content = fixed_content.rsplit("\n", 1)[0]

        # --- SAFETY CHECKS ---
        # 1. Check PHP Tag Count
        original_open_tags = content.count("<?php") + content.count("<?=")
        fixed_open_tags = fixed_content.count("<?php") + fixed_content.count("<?=")
        
        original_close_tags = content.count("?>")
        fixed_close_tags = fixed_content.count("?>")
        
        if original_open_tags != fixed_open_tags or original_close_tags != fixed_close_tags:
            print(f"SAFETY ERROR: PHP tag mismatch in {file_path}. Keeping original.")
            print(f"Original: {original_open_tags} open, {original_close_tags} close")
            print(f"Fixed:    {fixed_open_tags} open, {fixed_close_tags} close")
            return False

        # 2. Syntax Check (php -l)
        # We need to write to a temp file first to check syntax
        temp_check_path = file_path + ".check.php"
        try:
            with open(temp_check_path, 'w', encoding='utf-8') as f:
                f.write(fixed_content)
            
            # Run php -l
            import subprocess
            result = subprocess.run(['php', '-l', temp_check_path], capture_output=True, text=True)
            
            if result.returncode != 0:
                print(f"SAFETY ERROR: Syntax check failed for {file_path}. Keeping original.")
                print(result.stdout)
                print(result.stderr)
                os.remove(temp_check_path)
                return False
                
            os.remove(temp_check_path)
            
        except Exception as e:
            print(f"Error during syntax check: {e}")
            if os.path.exists(temp_check_path):
                os.remove(temp_check_path)
            return False
            
        # --- END SAFETY CHECKS ---
            
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(fixed_content)
            
        print(f"Successfully fixed {file_path}")
        return True

    except Exception as e:
        print(f"Error calling OpenAI for {file_path}: {e}")
        return False

def main():
    parser = argparse.ArgumentParser(description='Fix lint errors using OpenAI')
    parser.add_argument('--report', required=True, help='Path to linter JSON report')
    parser.add_argument('--linter', required=True, help='Name of the linter (eslint, stylelint, etc)')
    parser.add_argument('--file', help='Specific file to fix (optional)')
    args = parser.parse_args()

    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        print("Error: OPENAI_API_KEY environment variable not set.")
        sys.exit(1)

    try:
        with open(args.report, 'r', encoding='utf-8') as f:
            report_data = json.load(f)
    except FileNotFoundError:
        print(f"Report file not found: {args.report}")
        sys.exit(0) # Not an error, just nothing to fix
    except json.JSONDecodeError:
        print(f"Invalid JSON in report: {args.report}")
        sys.exit(1)

    # Group errors by ORIGINAL file path
    # This requires the linter output to map back to the original file, 
    # OR we map the temp file path back to the original.
    
    files_to_fix = {} 
    
    # Logic to parse specific linter formats and map temp paths to real paths
    # This part depends highly on the input JSON format of each linter.
    # For simplicity, we assume the workflow handles mapping or the "file" argument is mostly used.
    
    # Implementation specific to standard JSON outputs:
    if args.linter == 'eslint':
        # ESLint JSON: [ { "filePath": "...", "messages": [...] } ]
        for item in report_data:
            path = item.get('filePath', '')
            # Mapping logic: .tmp-changed/foo.js -> foo.js ? 
            # Or if running on original files?
            # User uses extracted files: .tmp-stylelint/path/to/file.page.style.css
            
            # Simple heuristic: try to find the real file in the path name
            real_path = path
            if ".tmp-" in path:
                # Extract relative part: .tmp-stylelint/dir/file.page.style.css -> dir/file.page
                # This is tricky. Let's assume the WORKFLOW passes the mapping or we assume structure.
                # Let's try to strip the temp prefix and extension suffix.
                pass 
                
            if item.get('messages'):
                if real_path not in files_to_fix:
                    files_to_fix[real_path] = []
                files_to_fix[real_path].extend(item['messages'])

    elif args.linter == 'stylelint':
        # Stylelint JSON: [ { "source": "...", "warnings": [...] } ]
        for item in report_data:
            path = item.get('source', '')
            if item.get('warnings'):
                 # Heuristic to find original file from temp extraction
                 # .tmp-stylelint/path/to/file.page.style.css
                 # We need to reconstruct 'path/to/file.page'
                 
                 # Remove prefix
                 clean_path = path.replace(".tmp-stylelint/", "").replace(".tmp-stylelint\\", "")
                 # Remove suffixes added by extraction script
                 # The script adds .style.css or .inline.css
                 clean_path = clean_path.replace(".style.css", "").replace(".inline.css", "")
                 
                 # Verify file exists
                 if os.path.exists(clean_path):
                     if clean_path not in files_to_fix:
                         files_to_fix[clean_path] = []
                     files_to_fix[clean_path].extend(item['warnings'])
                 else:
                     print(f"Skipping unknown file mapping: {path} -> {clean_path}")

    # Process each file
    for file_path, errors in files_to_fix.items():
        print(f"Fixing {file_path} with {len(errors)} errors...")
        fix_file(file_path, errors, api_key)

if __name__ == "__main__":
    main()
