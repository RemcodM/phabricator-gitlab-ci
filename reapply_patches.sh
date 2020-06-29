#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
pushd "${DIR}"

while read FILE; do
  [[ -f "${FILE}.patch" ]] || continue
  [[ -f "phabricator/${FILE}" ]] || continue
  [[ -f "${FILE}" ]] && rm -f "${FILE}"
  cp "phabricator/${FILE}" "${FILE}"
  patch -p 0 < "${FILE}.patch"
done < <(cat files)

popd