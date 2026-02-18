import pymupdf4llm
import ollama
import re
import json
import sys
import textwrap
from tqdm import tqdm

MODEL = "qwen3:8b"
IN_FILE = "Sport-PLUS.pdf"
OUT_FILE = "matura_final.md"
DEBUG_FILE = "debug_raw_extraction.md"

GRAY = "\033[90m"
CYAN = "\033[36m"
RESET = "\033[0m"
CLEAR_LINE = "\033[K"
MOVE_UP = "\033[A"

SYSTEM_PROMPT = """Du bist ein Experte für Sport-Matura. Deine Aufgabe ist die Konvertierung von Text in ein hochverdichtetes JSON-Lernskript.
Fokus: Fachterminologie, physiologische Prozesse, exakte Trainingsparameter und Kausalität.

STRUKTUR-VORGABEN:
- titel: Das Hauptthema.
- kern_thesen: Die wichtigsten 2-3 Kernaussagen des Kapitels.
- definitionen: Liste von Objekten mit { "begriff": "...", "inhalt": "..." }
- biologische_grundlagen: Zelluläre Ebene, Hormone, Nervensystem, anatomische Anpassungen.
- methodik_praxis: Genaue Zahlen (%, Wiederholungen, Pausen) und Trainingsregeln.
- kausalketten: "Wenn-Dann"-Zusammenhänge (z.B. Reiz -> Prozess -> Ergebnis).
- beispiele: Konkrete Sportarten oder Übungen zur Veranschaulichung.

KEIN FLIESS-TEXT. NUR STRUKTURIERTES JSON.
"""

def format_item(item):
    """Hilfsfunktion: Wandelt KI-Output (egal ob String oder Dict) in sauberen Text um."""
    if isinstance(item, dict):
        return " | ".join(f"{k}: {v}" for k, v in item.items())
    return str(item)

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
                {"role": "user", "content": f"Extrahiere das Lernwissen aus diesem Text:\n\n{chapter_text}"}
            ],
            options={"temperature": 0.1, "num_ctx": 32768},
            stream=True
        )

        for chunk in stream:
            if hasattr(chunk.message, 'thinking') and chunk.message.thinking:
                thinking_content += chunk.message.thinking
                
                clean_think = thinking_content.replace('\n', ' ')
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
        tqdm.write(f"{GRAY}[Fehler] {e}{RESET}")
        return None

print(f"{CYAN}--- Schritt 1: PDF Extraktion ---{RESET}")
try:
    full_md = pymupdf4llm.to_markdown(IN_FILE)
    with open(DEBUG_FILE, "w", encoding="utf-8") as f:
        f.write(full_md)
except Exception as e:
    print(f"Fehler bei PDF Extraktion: {e}")
    sys.exit(1)

chapters = [ch.strip() for ch in re.split(r'\n(?=(?:#+\s*)?\d+\.\d+_)', full_md) if ch.strip()]
print(f"Themengebiete gefunden: {len(chapters)}")

final_md = "# 🏆 Sport-Matura Finales Lernskript\n\n"
print(f"\n{CYAN}--- Schritt 2: KI-Analyse (Thinking Live) ---{RESET}")

with tqdm(chapters, desc="Verarbeitung", unit="Kapitel", dynamic_ncols=True) as pbar:
    for ch in pbar:
        current_title = ch.split('\n')[0].replace('#', '').strip()
        pbar.set_postfix(Thema=current_title)
        
        data = process_chapter(ch, pbar)
        
        if not data:
            continue

        md_block = f"\n# {data.get('titel', 'Unbenanntes Thema')}\n"
        
        # Kernthesen
        thesen = data.get("kern_thesen", [])
        if isinstance(thesen, list) and thesen:
            md_block += "> " + " • ".join(format_item(t) for t in thesen) + "\n\n"
        
        # Definitionen
        if data.get("definitionen"):
            md_block += "### 📝 Definitionen\n"
            for d in data["definitionen"]:
                if isinstance(d, dict):
                    md_block += f"- **{d.get('begriff', 'N/A')}**: {d.get('inhalt', '')}\n"
                else:
                    md_block += f"- {str(d)}\n"
        
        # Physiologie
        if data.get("biologische_grundlagen"):
            md_block += "\n### 🧬 Physiologie\n"
            bio = data["biologische_grundlagen"]
            for b in (bio if isinstance(bio, list) else [bio]):
                md_block += f"- {format_item(b)}\n"
        
        # Kausalketten
        if data.get("kausalketten"):
            md_block += "\n### ⛓️ Kausalketten\n"
            ketten = data["kausalketten"]
            for k in (ketten if isinstance(ketten, list) else [ketten]):
                md_block += f"- ➔ {format_item(k)}\n"
        
        # Methodik
        if data.get("methodik_praxis"):
            md_block += "\n### 📉 Methodik\n"
            meth = data["methodik_praxis"]
            for m in (meth if isinstance(meth, list) else [meth]):
                md_block += f"- {format_item(m)}\n"
        
        # Beispiele
        if data.get("beispiele"):
            md_block += "\n### 🏃 Beispiele\n"
            ex = data["beispiele"]
            if isinstance(ex, list):
                ex_str_list = [format_item(i) for i in ex]
                md_block += " - " + ", ".join(ex_str_list) + "\n"
            else:
                md_block += f" - {str(ex)}\n"

        final_md += md_block + "\n---\n"

# Speichern
with open(OUT_FILE, "w", encoding="utf-8") as f:
    f.write(final_md)

print(f"\n{CYAN}--- FERTIG! gespeichert in {OUT_FILE} ---{RESET}")