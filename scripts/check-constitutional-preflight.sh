#!/usr/bin/env bash
set -uo pipefail

PHASE="${CONSTITUTIONAL_PREFLIGHT_PHASE:-1}"
BASE_REF="${GITHUB_BASE_REF:-}"
PR_BODY_CONTENT="${PR_BODY:-}"
PR_BODY_PATH="${PR_BODY_FILE:-}"

mutation_scopes='^(app|routes|resources|database)/'

required_sections=(
    "Mutation Surface Audit"
    "Mutation Domain"
    "Theorem"
    "Forbidden Bridges"
    "Negative Invariants"
    "Surface Classification"
    "Lineage Gate"
)

declare -a risk_names=(
    "DIRECT_GRAPH_MUTATION"
    "DIRECT_GRAPH_MUTATION"
    "DIRECT_GRAPH_MUTATION"
    "AUTHORITY_LOGIC"
    "AUTHORITY_LOGIC"
    "AUTHORITY_LOGIC"
    "AUTHORITY_LOGIC"
    "ROLE_IDENTITY_LEAK"
    "ROLE_IDENTITY_LEAK"
    "ROLE_IDENTITY_LEAK"
    "LEGACY_AUTH_SURFACE"
    "LEGACY_AUTH_SURFACE"
    "LEGACY_AUTH_SURFACE"
    "LEGACY_AUTH_SURFACE"
)

declare -a risk_patterns=(
    'teams\(\)->attach'
    'updateExistingPivot'
    'detach\('
    'PolicyDecision'
    'consume'
    'revoke'
    'InfraLedger'
    '->role'
    'role_scope'
    'forceFill'
    'acceptInvitation'
    'login'
    'auth'
    'link'
)

declare -a risk_surfaces=(
    'teams()->attach'
    'updateExistingPivot'
    'detach('
    'PolicyDecision'
    'consume'
    'revoke'
    'InfraLedger'
    '->role'
    'role_scope'
    'forceFill'
    'acceptInvitation'
    'login'
    'auth'
    'link'
)

changed_files() {
    if [[ -n "${PREFLIGHT_CHANGED_FILES:-}" ]]; then
        printf '%s\n' "$PREFLIGHT_CHANGED_FILES"
        return
    fi

    if [[ -n "${PREFLIGHT_CHANGED_FILES_FILE:-}" && -f "$PREFLIGHT_CHANGED_FILES_FILE" ]]; then
        sed '/^[[:space:]]*$/d' "$PREFLIGHT_CHANGED_FILES_FILE"
        return
    fi

    if [[ -n "$BASE_REF" ]]; then
        git fetch --no-tags --depth=1 origin "$BASE_REF" >/dev/null 2>&1 || true
        git diff --name-only "origin/${BASE_REF}...HEAD" 2>/dev/null && return
    fi

    git diff --name-only HEAD 2>/dev/null || true
}

pr_body() {
    if [[ -n "$PR_BODY_PATH" && -f "$PR_BODY_PATH" ]]; then
        printf '%s\n' "$(tr '\n' ' ' < "$PR_BODY_PATH")"
        return
    fi

    printf '%s\n' "$PR_BODY_CONTENT"
}

print_header() {
    printf '%s\n' '[CONSTITUTIONAL-PREFLIGHT]'
}

print_no_risk() {
    print_header
    printf 'risk: none\n'
    printf 'status: OK\n'
    printf 'required: Mutation Surface Audit section only for mutation-domain changes\n'
}

report_risk() {
    local risk="$1"
    local surface="$2"
    local file="$3"
    local compliance_status="$4"

    print_header
    printf 'risk: %s\n' "$risk"
    printf 'surface: %s\n' "$surface"
    printf 'file: %s\n' "$file"
    printf 'status: %s\n' "$compliance_status"
    printf 'required: Mutation Surface Audit section\n'
}

body_has_required_sections() {
    local body="$1"

    for section in "${required_sections[@]}"; do
        if ! grep -Fqi "$section" <<< "$body"; then
            return 1
        fi
    done

    return 0
}

body="$(pr_body)"
files=()
while IFS= read -r file; do
    files+=("$file")
done < <(changed_files | sed '/^[[:space:]]*$/d' | sort -u)

scope_files=()
for file in "${files[@]}"; do
    if [[ "$file" =~ $mutation_scopes ]]; then
        scope_files+=("$file")
    fi
done

if [[ ${#scope_files[@]} -eq 0 ]]; then
    print_no_risk
    exit 0
fi

has_audit="false"
if body_has_required_sections "$body"; then
    has_audit="true"
fi

risk_count=0
for file in "${scope_files[@]}"; do
    [[ -f "$file" ]] || continue

    for index in "${!risk_patterns[@]}"; do
        pattern="${risk_patterns[$index]}"
        risk="${risk_names[$index]}"

        if grep -Eq -- "$pattern" "$file"; then
            risk_count=$((risk_count + 1))
            surface="${risk_surfaces[$index]}"
            if [[ "$has_audit" == "true" ]]; then
                report_risk "$risk" "$surface" "$file" "OK_WITH_AUDIT"
            else
                report_risk "$risk" "$surface" "$file" "ADVISORY"
            fi
        fi
    done
done

if [[ "$risk_count" -eq 0 ]]; then
    print_no_risk
    exit 0
fi

if [[ "$PHASE" == "2" && "$has_audit" != "true" ]]; then
    exit 1
fi

exit 0
