#!/bin/bash

while read FILE; do
  [[ -f "${FILE}.patch" ]] || continue
  [[ -f "phabricator/${FILE}" ]] || continue
  [[ -f "${FILE}" ]] && rm -f "${FILE}"
  cp "phabricator/${FILE}" "${FILE}"
  patch < "${FILE}.patch"
done < <(cat files)