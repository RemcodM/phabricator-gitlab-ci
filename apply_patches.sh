#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
pushd "${DIR}"

if [[ ! -d "$1" ]]; then
  echo "missing directory $1"
  exit 1
fi

while read FILE; do
  if [[ -f "${FILE}.patch" ]] && [[ -f "$1/${FILE}" ]]; then
    pushd "$1"
    patch -p 0 < "${DIR}/${FILE}.patch"
    popd
  elif [[ -f "${FILE}" ]] && [[ ! -f "$1/${FILE}" ]]; then
    cp "${FILE}" "$1/${FILE}"
  fi
done < <(cat files)

popd