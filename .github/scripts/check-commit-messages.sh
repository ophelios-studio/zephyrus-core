#!/usr/bin/env bash
# Usage: check-commit-messages.sh <git rev-list range>
#        check-commit-messages.sh --title <pull request title>
set -euo pipefail
export LC_ALL=C

ALLOWED_TYPES='feat fix docs refactor test chore perf ci build style revert'
read -r -a ALLOWED_LIST <<< "$ALLOWED_TYPES"
HEAD_RE='^([a-z]+)(\(([^()]*)\))?!?$'
UPPER_TYPE_RE='^([A-Za-z]+)(\(|!|$)'
SCOPE_RE='^[a-z0-9,._/-]+$'
GITHUB_COMMITTER='noreply@github.com'
CI_SKIP_RE='\[(skip ci|ci skip|no ci|skip actions|actions skip)\]'
# Random per run, so untrusted text cannot contain the token that resumes command processing.
stop_token="untrusted-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
[ "${#stop_token}" -eq 42 ] || exit 1

if [ "${1:-}" = --title ]; then
  title_mode=1
  if [ "$#" -ne 2 ]; then
    echo "usage: $0 --title <pull request title>" >&2
    exit 2
  fi
else
  title_mode=0
  if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "usage: $0 <git rev-list range>" >&2
    echo "       $0 --title <pull request title>" >&2
    exit 2
  fi
  range=$1
  if [[ "$range" == *..* ]]; then
    base=${range%%..*}
  else
    base="${range%^!}^"
  fi
fi

escape() {
  local s=$1
  s=${s//%/%25}
  s=${s//$'\r'/%0D}
  s=${s//$'\n'/%0A}
  printf '%s' "$s"
}

# Prints untrusted text with runner workflow commands stopped, so it cannot run one.
print_untrusted() {
  local prefix=$1 text=$2
  printf '::stop-commands::%s\n%s%s\n::%s::\n' "$stop_token" "$prefix" "$(escape "$text")" "$stop_token"
}

is_allowed_type() {
  local candidate
  for candidate in "${ALLOWED_LIST[@]}"; do
    if [ "$candidate" = "$1" ]; then
      return 0
    fi
  done
  return 1
}

reasons=()

# Reasons are fixed strings: annotation lines must never carry untrusted text.
join_reasons() {
  local joined="" reason
  for reason in "${reasons[@]}"; do
    joined+="${joined:+; }$reason"
  done
  printf '%s' "$joined"
}

check_subject() {
  local subject=$1 head rest type scope
  if [[ "$subject" != *": "* ]]; then
    reasons+=("missing ': ' after the type")
    return 0
  fi
  head=${subject%%": "*}
  rest=${subject#*": "}
  if ! [[ "$head" =~ $HEAD_RE ]]; then
    if [[ "$head" =~ $UPPER_TYPE_RE ]] && [[ "${BASH_REMATCH[1]}" == *[A-Z]* ]]; then
      reasons+=("type must be lowercase")
    else
      reasons+=("malformed type")
    fi
    return 0
  fi
  type=${BASH_REMATCH[1]}
  scope=${BASH_REMATCH[3]}
  if ! is_allowed_type "$type"; then
    reasons+=("unknown type")
  fi
  if [ -n "${BASH_REMATCH[2]}" ] && ! [[ "$scope" =~ $SCOPE_RE ]]; then
    reasons+=("scope must be lowercase [a-z0-9,._/-]")
  fi
  if [ -z "${rest//[[:space:]]/}" ]; then
    reasons+=("empty description")
  fi
  return 0
}

# Subject rules shared by commit messages and the pull request title.
check_subject_line() {
  local subject=$1
  case "$subject" in
    "fixup! "*|"squash! "*) reasons+=("starts with fixup! or squash!") ;;
    'Revert "'*) reasons+=("write a revert as 'revert: <subject>' on one line") ;;
    *) check_subject "$subject" ;;
  esac
}

# Text rules shared by commit messages and the pull request title.
check_text() {
  local text=$1
  if [[ "$text" == *[[:cntrl:]]* ]]; then
    reasons+=("contains a control character")
  fi
  if printf '%s\n' "$text" | grep -Eqi "$CI_SKIP_RE"; then
    reasons+=("contains a CI skip marker")
  fi
}

# Dependabot generates the body, so only its subject is checked. Exempt only PRs
# opened by dependabot[bot] from this repository: the head repo decides, not the actor.
is_dependabot_pull_request() {
  [ "${EVENT_NAME:-}" = pull_request ] && [ "${PR_AUTHOR:-}" = 'dependabot[bot]' ] \
    && [ -n "${REPOSITORY:-}" ] && [ "${PR_HEAD_REPO:-}" = "$REPOSITORY" ]
}

# A squash merge uses the pull request title as the commit subject, so it gets the subject rules.
check_title() {
  local title=$1
  reasons=()
  check_subject_line "$title"
  check_text "$title"
  if [ "${#reasons[@]}" -gt 0 ]; then
    printf '::error title=Pull request title::%s\n' "$(escape "$(join_reasons)")"
    print_untrusted '  Title: ' "$title"
    echo ""
    echo "Expected form: type(scope): description"
    echo "Allowed types: ${ALLOWED_TYPES// /, }"
    echo "Set the title to the commit message that dev will receive: GitHub squash-merges with it."
    return 1
  fi
  echo "Pull request title is valid."
}

if [ "$title_mode" -eq 1 ]; then
  check_title "$2" || exit 1
  exit 0
fi

checked=0
failed=0
commits=$(git rev-list --reverse "$range")

while IFS= read -r commit; do
  [ -n "$commit" ] || continue
  checked=$((checked + 1))
  reasons=()

  short=$(git rev-parse --short "$commit")
  msg=$(git show -s --format=%B "$commit")
  subject=$(printf '%s\n' "$msg" | grep -m 1 '[^[:space:]]' || true)
  if is_dependabot_pull_request; then
    msg=$subject
  fi
  nonempty=$(printf '%s\n' "$msg" | grep -c '[^[:space:]]' || true)
  committer=$(git show -s --format=%ce "$commit")
  read -r -a parents <<< "$(git show -s --format=%P "$commit")" || true

  if [ -z "$subject" ]; then
    reasons+=("empty message")
  elif [ "${#parents[@]}" -gt 1 ]; then
    if [ "$committer" != "$GITHUB_COMMITTER" ]; then
      reasons+=("merge commit: rebase instead of merging (git pull --rebase)")
    fi
  else
    if [ "$nonempty" -gt 1 ]; then
      reasons+=("has a body")
    fi
    check_subject_line "$subject"
  fi

  if printf '%s\n' "$msg" | grep -Eqi '^co-authored-by:'; then
    reasons+=("has a Co-Authored-By trailer")
  fi

  check_text "${msg//$'\n'/}"

  if [ "${#reasons[@]}" -gt 0 ]; then
    failed=$((failed + 1))
    printf '::error title=Commit %s::%s\n' "$short" "$(escape "$(join_reasons)")"
    print_untrusted "  $short  " "$subject"
  fi
done <<< "$commits"

echo "Checked $checked commit(s) in $range."

if [ "$failed" -gt 0 ]; then
  echo ""
  echo "Expected form: type(scope): description"
  echo "Allowed types: ${ALLOWED_TYPES// /, }"
  echo "Each commit is one line, with no body and no co-author trailer."
  case "${EVENT_NAME:-}" in
    pull_request)
      echo "To fix: git rebase -i --autosquash $base (reword or squash), then git push --force-with-lease."
      ;;
    push)
      echo "These commits are already published and must not be rewritten. The next commits must follow the rules."
      ;;
    *)
      echo "Unpushed commits: git rebase -i --autosquash $base (reword or squash)."
      echo "Commits already pushed must not be rewritten: the next commits must follow the rules."
      ;;
  esac
  echo "$failed commit(s) rejected." >&2
  exit 1
fi
