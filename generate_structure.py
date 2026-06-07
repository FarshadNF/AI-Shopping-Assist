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
    # پوشه‌هایی که باید نادیده گرفته شوند
    ignore = {'.git', 'venv', '__pycache__', 'data', '.idea', '.vscode', 'node_modules', 'logs', 'temp'}
    
    path = Path(directory)
    # فیلتر کردن فایل‌ها
    items = sorted([item for item in path.iterdir() if item.name not in ignore and not item.name.startswith('.')])
    
    for i, item in enumerate(items):
        is_last = i == len(items) - 1
        connector = "└── " if is_last else "├── "
        
        # اطلاعات حجم
        size_str = f" [{format_size(get_size(item))}]" if item.is_dir() else f" ({format_size(item.stat().st_size)})"
        line = f"{prefix}{connector}{item.name}{size_str}"
        
        print(line)
        if output_file:
            output_file.write(line + "\n")
            
        if item.is_dir():
            extension = "    " if is_last else "│   "
            print_tree(item, prefix + extension, output_file)

if __name__ == "__main__":
    output_filename = "project_structure.txt"
    print(f"Generating structure to {output_filename}...")
    
    with open(output_filename, "w", encoding="utf-8") as f:
        f.write("Nova Project Structure:\n")
        f.write(".\n")
        print("Nova Project Structure:")
        print(".")
        print_tree(".", output_file=f)
        
    print(f"\nDone! Please copy the content of {output_filename} and send it to me.")