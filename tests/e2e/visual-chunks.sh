#!/usr/bin/env bash
# Temporary CI transport for test-only screenshots when the artifact host is unreachable.
set -euo pipefail
name=$1
part=$2
case "$name" in testimonial-screen-editor-mobile|media-screen-editor-desktop|media-screen-editor-mobile|media-screen-public-desktop|media-screen-public-mobile|slideshow-screen-editor-desktop|slideshow-screen-editor-mobile|slideshow-screen-public-desktop|slideshow-screen-public-mobile|portfolio-screen-editor-desktop|portfolio-screen-editor-mobile|portfolio-screen-public-desktop|portfolio-screen-public-mobile) ;; *) exit 1 ;; esac
case "$part" in 0|1|2|3) ;; *) exit 1 ;; esac
mapfile -t captures < <(find builder/tests/e2e/test-results -type f -name "${name}.jpg")
test "${#captures[@]}" -eq 1
mapfile -t chunks < <(base64 -w0 "${captures[0]}" | fold -w 3500)
# Four steps per capture, at most ten notices per step and under 4096 bytes per notice.
test "${#chunks[@]}" -le 40
for (( i = part * 10; i < (part + 1) * 10 && i < ${#chunks[@]}; i++ )); do
    printf '::notice title=PBSCREEN-%s-%03d::%s\n' "$name" "$i" "${chunks[i]}"
done
