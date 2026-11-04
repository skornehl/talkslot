# Parents, texts and privacy (see README.md in this directory)
echo "Privacy: no browser page translation in the admin area"
# Admin page marked against the browser's page translation (cloud service): $1 path, $2 language (regex), $3 cookie jar
parents_notranslate() {
  local p
  p=$(curl -s ${3:+-b "$3"} "$B$1")
  echo "$p" | grep -q "<html lang=\"$2\" translate=\"no\" class=\"notranslate\">" \
    && echo "$p" | grep -q '<meta name="google" content="notranslate">'
}
check "login page (de/en): translate=\"no\", class and meta notranslate" 'parents_notranslate /admin/login de && parents_notranslate "/admin/login?lang=en" en'
check "round page and print view with booking list: marked as well" 'parents_notranslate "/admin/round/$RID" "[a-z]*" "$J" && parents_notranslate "/admin/round/$RID/print?email=1" "[a-z]*" "$J"'
check "parent pages stay translatable" 'P=$(curl -s "$B/"); echo "$P" | grep -q "<html lang=\"de\">" && ! echo "$P" | grep -q "notranslate"'
