import os
import sys
import json
import argparse
import google.generativeai as genai

def fix_file(file_path, errors, api_key):
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception as e:
        print(f"Error reading {file_path}: {e}")
        return False

    genai.configure(api_key=api_key)
    # Use flash for speed and safety
    model = genai.GenerativeModel('gemini-1.5-flash')

    error_desc = ""
    for err in errors:
        line = err.get('line', '?')
        msg = err.get('message', err.get('text', 'Unknown error'))
        error_desc += f"- Line {line}: {msg}\n"

    prompt = f"""
    You are a professional code fixer. Fix the linting errors in the code below.
    
    ### IMPORTANT RULES:
    1. ONLY return the fixed code. No explanations, no markdown blocks.
    2. DO NOT change any PHP tags (<?php, <?=, ?>) or logic.
    3. ONLY fix the CSS/HTML styling issues reported.
    4. If you cannot fix an error without breaking the file, return the ORIGINAL code exactly.
    
    ### FILE: {file_path}
    
    ### ERRORS:
    {error_desc}
    
    ### ORIGINAL CODE:
    {content}
    """

    try:
        response = model.generate_content(prompt)
        fixed_content = response.text.strip()
        
        # Basic sanity check: remove markdown code blocks
        if fixed_content.startswith("```"):
            fixed_content = fixed_content.split("\n", 1)[1]
            if fixed_content.startswith("php") or fixed_content.startswith("html") or fixed_content.startswith("css"):
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
        print(f"Error fixing {file_path} with Gemini: {e}")
        return False

def main():
    parser = argparse.ArgumentParser(description='Fix lint errors using Google Gemini')
    parser.add_argument('--report', required=True, help='Path to linter JSON report')
    parser.add_argument('--linter', required=True, help='Name of the linter (eslint, stylelint, etc)')
    parser.add_argument('--file', help='Specific file to fix (optional)')
    args = parser.parse_args()

    api_key = os.environ.get("GEMINI_API_KEY")
    if not api_key:
        print("Error: GEMINI_API_KEY environment variable not set.")
        sys.exit(1)

    try:
        with open(args.report, 'r') as f:
            report_data = json.load(f)
    except Exception as e:
        print(f"Error loading report {args.report}: {e}")
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
        for item in report_data:
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
