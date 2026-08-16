#!/bin/bash
set -Eeuo pipefail
umask 027

MGR_ROOT="${MGR_ROOT:-/usr/local/mgr5}"
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/src" && pwd)"
STATE_DIR="$MGR_ROOT/var/anore-payments"

if [ "$(id -u)" -ne 0 ]; then
  echo "Запустите установщик от root." >&2
  exit 1
fi

test -x "$MGR_ROOT/sbin/mgrctl" || {
  echo "BILLmanager 6 не найден в $MGR_ROOT" >&2
  exit 1
}

command -v php >/dev/null || {
  echo "PHP CLI не найден." >&2
  exit 1
}

php -r 'exit(version_compare(PHP_VERSION, "7.4.0", ">=") ? 0 : 1);' || {
  echo "Нужен PHP 7.4 или новее." >&2
  exit 1
}

for extension in curl json SimpleXML; do
  php -m | grep -qi "^${extension}$" || {
    echo "PHP-расширение ${extension} не установлено." >&2
    exit 1
  }
done

install -d -m 0755 "$MGR_ROOT/paymethods" "$MGR_ROOT/cgi" "$MGR_ROOT/etc/xml" "$MGR_ROOT/include/php"
install -d -m 0750 "$STATE_DIR"
chown --reference="$MGR_ROOT/var" "$STATE_DIR"

install -m 0755 "$SOURCE_DIR/paymethods/pmanore.php" "$MGR_ROOT/paymethods/pmanore.php"
install -m 0755 "$SOURCE_DIR/cgi/anorepayment.php" "$MGR_ROOT/cgi/anorepayment.php"
install -m 0755 "$SOURCE_DIR/cgi/anoreresult.php" "$MGR_ROOT/cgi/anoreresult.php"
install -m 0644 "$SOURCE_DIR/etc/xml/billmgr_mod_pmanore.php.xml" "$MGR_ROOT/etc/xml/billmgr_mod_pmanore.php.xml"
install -m 0644 "$SOURCE_DIR/include/php/anore_billmanager.php" "$MGR_ROOT/include/php/anore_billmanager.php"

php -l "$MGR_ROOT/paymethods/pmanore.php"
php -l "$MGR_ROOT/cgi/anorepayment.php"
php -l "$MGR_ROOT/cgi/anoreresult.php"
php -l "$MGR_ROOT/include/php/anore_billmanager.php"

"$MGR_ROOT/sbin/mgrctl" -m billmgr exit >/dev/null 2>&1 || true

echo "Модуль anore для BILLmanager 6 установлен."
echo "Callback: https://ВАШ-ДОМЕН-BILLMANAGER/mancgi/anoreresult.php"
