import pymupdf4llm
import ollama
import re
import json
import sys
import textwrap
from tqdm import tqdm

MODEL = "qwen3:14b"

IN_FILE = "Sport-PLUS.pdf"
OUT_FILE = "matura_dense.txt"
DEBUG_FILE = "debug_raw_extraction.md"

GRAY = "\033[90m"
CYAN = "\033[36m"
RESET = "\033[0m"
CLEAR_LINE = "\033[K"
MOVE_UP = "\033[A"

SYSTEM_PROMPT = """You are a DATA COMPRESSOR for LLMs. Convert text into MINIMALIST KNOWLEDGE GRAPHS.
Goal: 0% fluff, 100% signal.

RULES:
1. NO SENTENCES. NO GRAMMAR. NO FILLER WORDS (the, is, are, can).
2. SYNTAX:
   - Definitions: `Term=Def`
   - Logic: `Cause->Effect`, `Condition->Result`
   - Data: `Param=Value` (e.g., `Int=80%`, `Rest=90s`)
   - Lists: `Item,Item,Item`
3. OUTPUT JSON FORMAT:
   {
     "topic": "Subject",
     "concepts": ["Term=Def", "Term=Def"],
     "mech": ["A->B->C", "X stimulates Y"],
     "data": ["Int=80%", "Sets=3-5"]
   }
"""


def flatten_list(lst):
    """Flattens a list of strings into a single dense string."""
    if not lst:
        return ""
    return " | ".join([str(x).strip() for x in lst])


def process_chapter(chapter_text, pbar):
    full_response = ""
    thinking_content = ""
    num_thinking_lines = 5

    for _ in range(num_thinking_lines):
        sys.stdout.write("\n")
    sys.stdout.flush()

    try:
        stream = ollama.chat(
            model=MODEL,
            format="json",
            messages=[
                {"role": "system", "content": SYSTEM_PROMPT},
                {"role": "user", "content": f"COMPRESS THIS:\n\n{chapter_text}"},
            ],
            options={"temperature": 0.1, "num_ctx": 32768},
            stream=True,
        )

        for chunk in stream:
            if hasattr(chunk.message, "thinking") and chunk.message.thinking:
                thinking_content += chunk.message.thinking
                clean_think = thinking_content.replace("\n", " ")
                wrapped = textwrap.wrap(clean_think, width=100)
                display_lines = wrapped[-num_thinking_lines:]
                while len(display_lines) < num_thinking_lines:
                    display_lines.insert(0, "")
                sys.stdout.write(MOVE_UP * num_thinking_lines)
                for line in display_lines:
                    sys.stdout.write(f"\r{CLEAR_LINE}{GRAY}Thinking: {line}{RESET}\n")
                sys.stdout.flush()

            if chunk.message.content:
                full_response += chunk.message.content

        sys.stdout.write(MOVE_UP * num_thinking_lines)
        for _ in range(num_thinking_lines):
            sys.stdout.write(f"\r{CLEAR_LINE}\n")
        sys.stdout.write(MOVE_UP * num_thinking_lines)
        sys.stdout.flush()

        return json.loads(full_response)

    except Exception as e:
        sys.stdout.write(MOVE_UP * num_thinking_lines)
        tqdm.write(f"{GRAY}[Error] {e}{RESET}")
        return None


print(f"{CYAN}--- Step 1: PDF Extraction ---{RESET}")
try:
    full_md = pymupdf4llm.to_markdown(IN_FILE)
except Exception as e:
    print(f"Error extracting PDF: {e}")
    sys.exit(1)

chapters = [
    ch.strip() for ch in re.split(r"\n(?=(?:#+\s*)?\d+\.\d+_)", full_md) if ch.strip()
]
print(f"Chunks found: {len(chapters)}")

with open(OUT_FILE, "w", encoding="utf-8") as f:
    f.write("CONTEXT_DATA_START\n")

print(f"\n{CYAN}--- Step 2: Semantic Compression ---{RESET}")

with tqdm(chapters, desc="Compressing", unit="chunk", dynamic_ncols=True) as pbar:
    for ch in pbar:
        current_title = ch.split("\n")[0].replace("#", "").strip()[:20]
        pbar.set_postfix(Topic=current_title)

        data = process_chapter(ch, pbar)

        if not data:
            continue

        topic = data.get("topic", "Topic").upper()
        concepts = flatten_list(data.get("concepts", []))
        mech = flatten_list(data.get("mech", []))
        data_points = flatten_list(data.get("data", []))

        block = f"[{topic}]\n"
        if concepts:
            block += f"DEF:{concepts}\n"
        if mech:
            block += f"BIO:{mech}\n"
        if data_points:
            block += f"DAT:{data_points}\n"

        with open(OUT_FILE, "a", encoding="utf-8") as f:
            f.write(block + "\n")

print(f"\n{CYAN}--- DONE! Saved to {OUT_FILE} ---{RESET}")
