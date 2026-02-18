import tiktoken

text = open("matura_final.md", encoding="utf-8").read()
print(f"Tokens: {len(tiktoken.encoding_for_model('gpt-4o').encode(text))}")