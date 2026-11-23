#!/usr/bin/env bash
# Temporary CI transport for test-only screenshots when the artifact host is unreachable.
set -euo pipefail
name=$1
part=$2
case "$name" in entry-screen-editor-desktop|entry-screen-editor-mobile|entry-screen-public-desktop|entry-screen-public-mobile|context-screen-editor-desktop|context-screen-editor-mobile|context-screen-public-desktop|context-screen-public-mobile|archive-screen-editor-desktop|archive-screen-editor-mobile|archive-screen-public-desktop|archive-screen-public-mobile|partial-entry-screen-public-desktop|partial-entry-screen-public-mobile|content-screen-editor-desktop|content-screen-editor-mobile|content-screen-public-desktop|content-screen-public-mobile|topbar-screen-editor-320|topbar-screen-editor-375|trail-screen-public-desktop|trail-screen-public-mobile|search-partial-public-desktop|search-partial-public-mobile|navigation-partial-desktop|navigation-partial-mobile|navigation-breakpoint-850|navigation-breakpoint-600|share-partial-desktop|share-partial-mobile|visibility-editor|visibility-public-tablet|visibility-public-mobile|position-editor|position-editor-mobile|position-public-desktop|position-public-mobile|original-media-editor|original-media-editor-mobile|original-media-public|original-media-public-mobile|widget-media-editor|widget-media-editor-mobile|widget-media-public|widget-media-public-mobile|spacing-editor|spacing-editor-mobile|spacing-public|spacing-public-mobile|variant-editor|variant-editor-mobile|variant-public|variant-public-mobile) ;; *) exit 1 ;; esac
case "$part" in 0|1|2|3) ;; *) exit 1 ;; esac
mapfile -t captures < <(find builder/tests/e2e/test-results -type f -name "${name}.jpg")
test "${#captures[@]}" -eq 1
mapfile -t chunks < <(base64 -w0 "${captures[0]}" | fold -w 3500)
# Four steps per capture, at most ten notices per step and under 4096 bytes per notice.
test "${#chunks[@]}" -le 40
for (( i = part * 10; i < (part + 1) * 10 && i < ${#chunks[@]}; i++ )); do
    printf '::notice title=PBSCREEN-%s-%03d::%s\n' "$name" "$i" "${chunks[i]}"
done
