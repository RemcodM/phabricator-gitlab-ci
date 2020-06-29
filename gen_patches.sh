#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
pushd "${DIR}"

while read FILE; do
  [[ -f "${FILE}" ]] || continue
  [[ -f "phabricator/${FILE}" ]] || continue
  diff -Naur "phabricator/${FILE}" "${FILE}" > "${FILE}.patch"
done < <(cat files)

popd