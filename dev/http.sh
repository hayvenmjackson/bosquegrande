#!/usr/bin/env bash
# HTTP-level checks against a running disposable site.
# Needs mail-capture.php in the site's mu-plugins folder.
#   BG_TEST_DISPOSABLE=1 BASE=http://localhost:8080 WPDIR=/path/to/wordpress dev/http.sh
set -u
if [ "${BG_TEST_DISPOSABLE:-}" != "1" ]; then
  echo "These tests write to the database. Run them only against a disposable test site, with BG_TEST_DISPOSABLE=1." >&2
  exit 2
fi
BASE=${BASE:?Set BASE to the site URL, for example http://localhost:8080}
WPDIR=${WPDIR:?Set WPDIR to the WordPress folder}
MAIL=${MAIL:-$(${PHP:-php} -r 'echo sys_get_temp_dir();')/bosquegrande-mail.jsonl}
PHP=${PHP:-php}
host=$(echo "$BASE" | sed -E 's#^https?://##; s#/.*$##')
fail=0
ok() { echo "PASS $1"; }
bad() { echo "FAIL $1"; fail=$((fail+1)); }
c() { curl -s --noproxy "${host%%:*}" "$@"; }
: > "$MAIL"

email="sub$RANDOM@example.test"

# 1. Subscribe: neutral response, then a confirmation email with an opaque token.
resp=$(c -X POST "$BASE/wp-json/bosquegrande/v1/subscribe" -d "email=$email&group=general")
echo "$resp" | grep -q '"ok":true' && ok "subscribe accepted" || bad "subscribe accepted: $resp"
sleep 1
token=$(python3 -c "
import json,re,sys
for l in open(sys.argv[1]):
    m=json.loads(l)
    to=m['to'] if isinstance(m['to'],str) else ' '.join(m['to'])
    if sys.argv[2] in to:
        r=re.search(r'bg_sub_confirm=(bgc2_[a-f0-9]{48})', m['message'])
        if r: print(r.group(1))
" "$MAIL" "$email" | tail -1)
[ -n "$token" ] && ok "confirmation email carries an opaque token" || bad "confirmation email token"

# 2. GET on the confirm link only shows a button; POST confirms.
page=$(c "$BASE/?bg_sub_confirm=$token")
echo "$page" | grep -q 'name="bg_sub_confirm"' && ok "confirm GET shows a button (no state change)" || bad "confirm GET"
page=$(c -X POST "$BASE/" -d "bg_sub_confirm=$token")
echo "$page" | grep -qi 'subscribed\|confirmed' && ok "confirm POST subscribes" || bad "confirm POST"

# 3. The unsubscribe link from a newsletter: GET asks, POST unsubscribes.
unsub=$(BG_TEST_HOST="$host" "$PHP" -r '$_SERVER["HTTP_HOST"]=getenv("BG_TEST_HOST");$_SERVER["REQUEST_URI"]="/";require $argv[1]."/wp-load.php";$c=\BosqueGrande\Core\Plugin::instance()->container();$id=(int)($c->get("constituents.store")->idsByEmail($argv[2])[0]??0);$g=$c->get("correspondence.groups")->bySlug("general");echo \BosqueGrande\Modules\Correspondence\Composer::sign(["a"=>"unsub","c"=>$id,"g"=>(int)$g->id]);' "$WPDIR" "$email")
page=$(c "$BASE/?bg_unsub=$unsub")
echo "$page" | grep -q 'name="bg_unsub_confirm"' && ok "unsubscribe GET asks first (no state change)" || bad "unsubscribe GET"
page=$(c -X POST "$BASE/" -d "bg_unsub_confirm=$unsub")
echo "$page" | grep -qi 'not valid' && bad "unsubscribe POST rejected a valid link" || ok "unsubscribe POST accepts the newsletter link"
status=$(BG_TEST_HOST="$host" "$PHP" -r '$_SERVER["HTTP_HOST"]=getenv("BG_TEST_HOST");$_SERVER["REQUEST_URI"]="/";require $argv[1]."/wp-load.php";$c=\BosqueGrande\Core\Plugin::instance()->container();$id=(int)($c->get("constituents.store")->idsByEmail($argv[2])[0]??0);$g=$c->get("correspondence.groups")->bySlug("general");echo $c->get("correspondence.groups")->subscriptionStatus($id,(int)$g->id);' "$WPDIR" "$email")
[ "$status" = "unsubscribed" ] && ok "reader is unsubscribed" || bad "subscription status after unsubscribe: $status"
page=$(c -X POST "$BASE/" -d "bg_unsub_confirm=${unsub}x")
echo "$page" | grep -qi 'not valid' && ok "tampered unsubscribe link refused" || bad "tampered unsubscribe link accepted"

# 4. A forged one-click unsubscribe is refused.
code=$(c -o /dev/null -w "%{http_code}" -X POST "$BASE/wp-json/bosquegrande/v1/unsubscribe" -d "t=forged")
[ "$code" = "400" ] && ok "forged one-click unsubscribe refused" || bad "forged unsubscribe returned $code"

# 5. Sign-in link requests get the same answer for known and unknown emails.
r1=$(c -X POST "$BASE/wp-json/bosquegrande/v1/identity/request-link" -d "email=$email&first_name=A&last_name=B")
r2=$(c -X POST "$BASE/wp-json/bosquegrande/v1/identity/request-link" -d "email=nobody$RANDOM@example.test&first_name=A&last_name=B")
m1=$(echo "$r1" | python3 -c "import sys,json; print(json.load(sys.stdin).get('message',''))" 2>/dev/null)
m2=$(echo "$r2" | python3 -c "import sys,json; print(json.load(sys.stdin).get('message',''))" 2>/dev/null)
[ -n "$m1" ] && [ "$m1" = "$m2" ] && ok "sign-in link request is neutral" || bad "sign-in link responses differ: [$m1] vs [$m2]"

# 6. The public calendar renders without PHP errors.
BG_TEST_HOST="$host" "$PHP" -r '$_SERVER["HTTP_HOST"]=getenv("BG_TEST_HOST");$_SERVER["REQUEST_URI"]="/";require $argv[1]."/wp-load.php";if(!get_page_by_path("smoke-calendar")){wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_name"=>"smoke-calendar","post_title"=>"Calendar","post_content"=>"[bosquegrande]"]);}' "$WPDIR"
page=$(c "$BASE/smoke-calendar/")
echo "$page" | grep -qiE 'fatal error|<b>warning</b>|<b>notice</b>' && bad "calendar page has PHP errors" || ok "calendar page renders cleanly"
echo "$page" | grep -q 'bgc-' && ok "calendar markup present" || bad "calendar markup missing"
echo "$page" | grep -q $'\xe2\x80\x94' && bad "calendar page contains an em dash" || ok "no em dash on calendar page"

# 7. My Profile renders for a signed-out visitor.
page=$(c "$BASE/?bg_profile=1")
echo "$page" | grep -qiE 'fatal error|<b>warning</b>' && bad "profile page errors" || ok "profile request renders"

[ $fail = 0 ] && echo "ALL PASS" || echo "$fail FAILED"
exit $fail
