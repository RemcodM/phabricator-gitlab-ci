#!/bin/bash

while read FILE; do
  [[ -f "${FILE}" ]] || continue
  [[ -f "phabricator/${FILE}" ]] || continue
  diff -Naur "phabricator/${FILE}" "${FILE}" > "${FILE}.patch"
done < <(cat files)