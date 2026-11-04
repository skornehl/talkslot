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

echo "Language choice for parents"
# Own rounds (fresh slots), one per setting for multiple bookings; removed again at the end
T=$(curl -s -b "$J" "$B/admin" | csrf)
IVR=$(mkround Einladung+Runde round); IVD=$(mkround Einladung+Tag day); IVN=$(mkround Einladung+frei none)
IVS=$(slugof "$IVR")
LANGP=$(curl -s -D - -H "Accept-Language: tr-TR,tr;q=0.9,de;q=0.8" "$B/$IVS")
check "language names in the header, above the heading (booking form: buttons that keep the input)" 'echo "$LANGP" | tr -d "\n" | grep -q "<header.*class=\"lang-switch\".*lang=\"tr\">Türkçe</button>.*</header>.*<h1>" && [ "$(echo "$LANGP" | grep -c "^ *<button type=\"submit\" form=\"booking-form\" name=\"switch_lang\" value=\"[a-z]*\" formnovalidate class=\"button-link\" lang=\"[a-z]*\"\( aria-current=\"true\"\)\?>[^<]*</button>$")" = 6 ] && ! echo "$LANGP" | grep -q "flags/"'
check "Enter books: an invisible plain submit button of the booking form comes before the language buttons" '[ "$(echo "$LANGP" | grep -n "data-default-submit" | cut -d: -f1)" -lt "$(echo "$LANGP" | grep -n "name=\"switch_lang\"" | head -1 | cut -d: -f1)" ]'
check "browser language Turkish: page stays German, a button in Turkish is offered, no cookie, Vary" 'echo "$LANGP" | grep -q "<html lang=\"de\">" && echo "$LANGP" | grep -q "<div class=\"alert alert-info lang-hint\" lang=\"tr\"><button type=\"submit\" form=\"booking-form\" name=\"switch_lang\" value=\"tr\" formnovalidate class=\"button-link\" lang=\"tr\">Bu sayfayı Türkçe görüntüle</button></div>" && ! echo "$LANGP" | grep -qi "^set-cookie" && echo "$LANGP" | grep -qi "^vary: accept-language"'
HINT=$(curl -s -H "Accept-Language: fr-FR,fr;q=0.9,en;q=0.8,de;q=0.7" "$B/" | grep -o '<a href="[^"]*" hreflang="en" lang="en">View this page in English</a>' | sed 's/<a href="//;s/".*//')
check "home page: the first offered browser language is linked in that language and opens the page in it ($HINT)" '[ "$HINT" = "/?lang=en" ] && curl -s "$B$HINT" | grep -q "<html lang=\"en\">"'
check "no hint when German is preferred or a language was chosen" '! curl -s -H "Accept-Language: de-DE,de;q=0.9,en;q=0.8" "$B/" | grep -q "lang-hint" && ! curl -s -H "Accept-Language: tr" "$B/$IVS?lang=tr" | grep -q "lang-hint" && ! curl -s -H "Accept-Language: tr" "$B/$IVS?lang=en" | grep -q "lang-hint"'
SWH=$(curl -s -w '\n%{http_code}' -H "Accept-Language: uk" --data-urlencode "csrf=$(echo "$LANGP" | csrf)" -d "lang=de&switch_lang=uk&name=Olena+Movna&privacy=1" "$B/$IVS")
check "switching via the hint keeps the input and books nothing" '[ "$(echo "$SWH" | tail -1)" = 200 ] && echo "$SWH" | grep -q "<html lang=\"uk\">" && echo "$SWH" | grep -q "value=\"Olena Movna\"" && ! echo "$SWH" | grep -q "lang-hint"'

# Invitation text: direct link per further language, sentence on multiple bookings as on the round page
inv() { curl -s -b "$J" "$B/admin/round/$1" | sed -n '/<pre class="invitation">/,/<\/pre>/p'; }
INV=$(inv "$IVR")
check "invitation: one line per further language with its name and a direct link" 'echo "$INV" | grep -qx "English: $B/$IVS?lang=en" && echo "$INV" | grep -qx "Polski: $B/$IVS?lang=pl" && echo "$INV" | grep -qx "Русский: $B/$IVS?lang=ru" && echo "$INV" | grep -qx "Українська: $B/$IVS?lang=uk" && echo "$INV" | grep -qx "Türkçe: $B/$IVS?lang=tr" && ! echo "$INV" | grep -q "lang=de"'
check "invitation: the language links are in the mail link too" 'curl -s -b "$J" "$B/admin/round/$IVR" | grep -o "href=\"mailto:[^\"]*\"" | grep -q "T%C3%BCrk%C3%A7e%3A%20http[^\"]*%3Flang%3Dtr"'
check "invitation: one appointment per person → the sibling rule of the round page" 'echo "$INV" | grep -q "^Pro Person ist nur ein Termin möglich. Für Geschwister buchen Sie bitte jeweils mit dem Namen des Kindes. Nach der Buchung erhalten Sie" && ! echo "$INV" | grep -q "Bitte buchen Sie nur einen Termin" && curl -s "$B/$IVS" | grep -q "Pro Person ist nur ein Termin möglich. Für Geschwister"'
check "invitation: one per day and no limit → the sentence of the respective round page" 'inv "$IVD" | grep -q "^Pro Person ist nur ein Termin pro Tag möglich. Für Geschwister buchen Sie bitte jeweils mit dem Namen des Kindes. Nach der Buchung" && inv "$IVN" | grep -q "^Bitte buchen Sie nur einen Termin. Nach der Buchung" && curl -s "$B/$(slugof "$IVN")" | grep -q ">Bitte buchen Sie nur einen Termin.<"'
for r in $IVR $IVD $IVN; do curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$r/delete"; done
check "test rounds of the invitation removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($IVR, $IVD, $IVN)")" = 0 ]'
