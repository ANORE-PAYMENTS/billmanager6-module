#!/bin/bash
set -Eeuo pipefail
umask 027

MGR_ROOT="${MGR_ROOT:-/usr/local/mgr5}"
MODULE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$MODULE_DIR/src"
STATE_DIR="$MGR_ROOT/var/anore-payments"
PHP_BIN='/usr/bin/php'

if [ "$MGR_ROOT" != '/usr/local/mgr5' ]; then
  echo "Поддерживается только BILLmanager в /usr/local/mgr5: bill_util.php использует этот путь." >&2
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "Запустите установщик от root." >&2
  exit 1
fi

test -x "$MGR_ROOT/sbin/mgrctl" || {
  echo "BILLmanager 6 не найден в $MGR_ROOT" >&2
  exit 1
}

test -x "$PHP_BIN" || {
  echo "PHP CLI не найден в $PHP_BIN." >&2
  exit 1
}

"$PHP_BIN" -r 'exit(PHP_SAPI === "cli" && version_compare(PHP_VERSION, "7.4.0", ">=") ? 0 : 1);' || {
  echo "Нужен PHP 7.4 или новее." >&2
  exit 1
}

for extension in curl json SimpleXML mysqli; do
  "$PHP_BIN" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension" || {
    echo "PHP-расширение ${extension} не установлено." >&2
    exit 1
  }
done

"$PHP_BIN" -r '$disabled = array_map("trim", explode(",", (string) ini_get("disable_functions"))); exit(function_exists("exec") && !in_array("exec", $disabled, true) ? 0 : 1);' || {
  echo "PHP-функция exec должна быть разрешена для вызова BILLmanager." >&2
  exit 1
}

test -r "$MGR_ROOT/include/php/bill_util.php" || {
  echo "Не найден $MGR_ROOT/include/php/bill_util.php. Установите PHP-библиотеку BILLmanager перед модулем." >&2
  exit 1
}

for source in \
  "$SOURCE_DIR/paymethods/pmanore.php" \
  "$SOURCE_DIR/cgi/anorepayment.php" \
  "$SOURCE_DIR/cgi/anoreresult.php" \
  "$SOURCE_DIR/include/php/anore_billmanager.php" \
  "$MODULE_DIR/scripts/recover.php" \
  "$MGR_ROOT/include/php/bill_util.php"; do
  "$PHP_BIN" -l "$source"
done

"$PHP_BIN" -r 'libxml_use_internal_errors(true); $xml = simplexml_load_file($argv[1], "SimpleXMLElement", LIBXML_NONET); if ($xml === false || $xml->getName() !== "mgrdata" || !isset($xml->plugin)) { fwrite(STDERR, "Некорректное XML-описание модуля.\n"); exit(1); }' \
  "$SOURCE_DIR/etc/xml/billmgr_mod_pmanore.php.xml"

install -d -m 0755 "$MGR_ROOT/paymethods" "$MGR_ROOT/cgi" "$MGR_ROOT/etc/xml" "$MGR_ROOT/include/php"
install -d -m 0750 "$STATE_DIR"
chown --reference="$MGR_ROOT/var" "$STATE_DIR"

install -m 0755 "$SOURCE_DIR/paymethods/pmanore.php" "$MGR_ROOT/paymethods/pmanore.php"
install -m 0755 "$SOURCE_DIR/cgi/anorepayment.php" "$MGR_ROOT/cgi/anorepayment.php"
install -m 0755 "$SOURCE_DIR/cgi/anoreresult.php" "$MGR_ROOT/cgi/anoreresult.php"
install -m 0644 "$SOURCE_DIR/etc/xml/billmgr_mod_pmanore.php.xml" "$MGR_ROOT/etc/xml/billmgr_mod_pmanore.php.xml"
install -m 0644 "$SOURCE_DIR/include/php/anore_billmanager.php" "$MGR_ROOT/include/php/anore_billmanager.php"
install -m 0755 "$MODULE_DIR/scripts/recover.php" "$MGR_ROOT/paymethods/anore-recover.php"

"$MGR_ROOT/sbin/mgrctl" -m billmgr exit >/dev/null 2>&1 || true

echo "Модуль anore для BILLmanager 6 установлен."
echo "Callback: https://ВАШ-ДОМЕН-BILLMANAGER/mancgi/anoreresult.php"
echo "Проверьте совпадение секрета webhook в магазине anore и платёжном методе BILLmanager."
