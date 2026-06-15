import os
from pathlib import Path

def get_size(path):
    """محاسبه حجم پوشه یا فایل"""
    try:
        if path.is_file():
            return path.stat().st_size
        return sum(f.stat().st_size for f in path.glob('**/*') if f.is_file())
    except:
        return 0

def format_size(size):
    """تبدیل بایت به کیلوبایت/مگابایت"""
    for unit in ['B', 'KB', 'MB', 'GB']:
        if size < 1024:
            return f"{size:.2f}{unit}"
        size /= 1024
    return f"{size:.2f}TB"

def print_tree(directory, prefix="", output_file=None):
    ignore = {'.git', 'venv', '__pycache__', 'data', '.idea', '.vscode', 'node_modules', 'logs', 'temp'}
    path = Path(directory)
    items = sorted([item for item in path.iterdir() if item.name not in ignore and not item.name.startswith('.')])
    
    for i, item in enumerate(items):
        is_last = i == len(items) - 1
        connector = "└── " if is_last else "├── "
        
        size_str = f" [{format_size(get_size(item))}]" if item.is_dir() else f" ({format_size(item.stat().st_size)})"
        line = f"{prefix}{connector}{item.name}{size_str}"
        
        print(line)
        if output_file:
            output_file.write(line + "\n")
            
        if item.is_dir():
            extension = "    " if is_last else "│   "
            print_tree(item, prefix + extension, output_file)

def append_file_contents(directory, output_file):
    """خواندن و چسباندن محتوای کدهای پروژه به فایل خروجی"""
    # پسوندهای مجاز برای خواندن کدها
    allowed_extensions = {'.py', '.js', '.css', '.html'}
    # نادیده گرفتن فایل‌های دیتابیس یا فایل‌های حجیم کانفیگ
    ignore_files = {'manage.py', 'generate_structure.py'}
    ignore_dirs = {'.git', 'venv', '__pycache__', 'data', '.idea', '.vscode', 'node_modules', 'logs', 'temp'}

    output_file.write("\n\n" + "="*60 + "\n")
    output_file.write("SOURCE CODES CONTENT\n")
    output_file.write("="*60 + "\n\n")

    for root, dirs, files in os.walk(directory):
        # فیلتر کردن پوشه‌های غیرضروری
        dirs[:] = [d for d in dirs if d not in ignore_dirs and not d.startswith('.')]
        
        for file in files:
            path = Path(root) / file
            if path.suffix in allowed_extensions and file not in ignore_files and not file.startswith('.'):
                try:
                    with open(path, 'r', encoding='utf-8') as f:
                        content = f.read()
                    
                    output_file.write(f"\n\n--- FILE: {path} ---\n")
                    output_file.write(content)
                    output_file.write(f"\n--- END OF {file} ---\n")
                except Exception as e:
                    output_file.write(f"\n[Could not read file {path}: {e}]\n")

if __name__ == "__main__":
    output_filename = "project_structure_with_codes.txt"
    print(f"Generating structure and collecting codes to {output_filename}...")
    
    with open(output_filename, "w", encoding="utf-8") as f:
        f.write("AI Chatbot Assistant - Full Project Context:\n")
        f.write(".\n")
        print("Building tree...")
        print_tree(".", output_file=f)
        
        print("Collecting source codes...")
        append_file_contents(".", output_file=f)
        
    print(f"\nDone! Please copy the content of '{output_filename}' and send it to me.")