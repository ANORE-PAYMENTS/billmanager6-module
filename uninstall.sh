#!/bin/bash
set -Eeuo pipefail

MGR_ROOT="${MGR_ROOT:-/usr/local/mgr5}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Запустите удаление от root." >&2
  exit 1
fi

rm -f -- \
  "$MGR_ROOT/paymethods/pmanore.php" \
  "$MGR_ROOT/cgi/anorepayment.php" \
  "$MGR_ROOT/cgi/anoreresult.php" \
  "$MGR_ROOT/etc/xml/billmgr_mod_pmanore.php.xml" \
  "$MGR_ROOT/include/php/anore_billmanager.php"

if [ -x "$MGR_ROOT/sbin/mgrctl" ]; then
  "$MGR_ROOT/sbin/mgrctl" -m billmgr exit >/dev/null 2>&1 || true
fi

echo "Модуль удалён. Сопоставления платежей оставлены в $MGR_ROOT/var/anore-payments."

