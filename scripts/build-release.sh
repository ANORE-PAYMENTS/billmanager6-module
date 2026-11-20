#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C
export TZ=UTC

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
output_directory=${1:-"$repository_root/dist"}
archive="$output_directory/billmanager6-module.tar.gz"
checksum="$archive.sha256"

mkdir -p "$output_directory"
rm -f "$archive" "$checksum"

git -C "$repository_root" archive \
  --format=tar \
  --prefix=billmanager6-module/ \
  HEAD \
  | gzip -n -9 > "$archive"

(
  cd "$output_directory"
  sha256sum "$(basename "$archive")" > "$(basename "$checksum")"
)
