#!/usr/bin/env bash

# Shared by the tests:* tasks in mise/tasks/tests/ - sourced, not run.
# The runner runs in the neos container of the distribution (SUT, default neos9), the browser in its playwright-bridge
# container.

E2E_COMPOSE_FILE="$MISE_PROJECT_ROOT/Tests/SystemUnderTest/${SUT:-neos9}/docker-compose.yml"
# the package as the container sees it (Tests/SystemUnderTest/docker-compose.base.yml mounts it there)
E2E_PACKAGE_IN_CONTAINER=/app/DistributionPackages/Sandstorm.E2ETestTools
E2E_FEATURES_DIR="$MISE_PROJECT_ROOT/Tests/E2E/Features"
E2E_RESULTS_DIR="$MISE_PROJECT_ROOT/Tests/SystemUnderTest/e2e-results"
E2E_BRIDGE_DIR="$MISE_PROJECT_ROOT/Templates/playwright-bridge"

e2e_compose() {
    docker compose -f "$E2E_COMPOSE_FILE" "$@"
}

# $1: phpunit config (UnitTests.xml, FunctionalTests.xml), $2: test directory of the package
e2e_phpunit() {
    e2e_compose exec -T -e FLOW_CONTEXT=Testing neos ./bin/phpunit -c "Build/BuildEssentials/PhpUnit/$1" "$E2E_PACKAGE_IN_CONTAINER/$2"
}

# Behat runs a scenario only by the line of its "Scenario:" (or of an Examples row) - any other line, e.g. of a step,
# finds nothing. Prints the line of the scenario around $2 in feature file $1, nothing for the Background/Feature head.
e2e_scenario_line() {
    awk -v target="$2" '
        NR > target { exit }
        /^[ \t]*(Scenario|Scenario Outline|Scenario Template|Example):/ { found = NR; inExamples = 0; next }
        /^[ \t]*(Examples|Scenarios):/ { inExamples = 1; header = 1; next }
        /^[ \t]*(Background|Feature|Rule):/ { found = ""; inExamples = 0; next }
        inExamples && /^[ \t]*\|/ { if (header) header = 0; else if (NR == target) found = NR }
        END { print found }
    ' "$1"
}

# A feature file (with :line) as Behat in the container sees it - $1 relative to Tests/E2E/Features or to where mise was
# called
e2e_feature_path() {
    local path="$1" file="${1%%:*}" line=""
    [ "$file" != "$path" ] && line="${path#*:}"
    local hostFile=""
    if [ -f "$E2E_FEATURES_DIR/$file" ]; then
        hostFile="$E2E_FEATURES_DIR/$file"
    elif [ -f "${MISE_ORIGINAL_CWD:-.}/$file" ]; then
        hostFile="${MISE_ORIGINAL_CWD:-.}/$file"
    else
        echo "Feature file not found: $file (relative to Tests/E2E/Features or the current directory)" >&2
        return 1
    fi
    hostFile="$(cd "$(dirname "$hostFile")" && pwd)/$(basename "$hostFile")"
    case "$hostFile" in
        "$MISE_PROJECT_ROOT"/*) ;;
        *) echo "Not a feature file of this package: $hostFile" >&2; return 1 ;;
    esac
    if [ -n "$line" ]; then
        local scenarioLine
        scenarioLine=$(e2e_scenario_line "$hostFile" "$line")
        if [ -z "$scenarioLine" ]; then
            echo "Line $line is in no scenario - running the whole feature" >&2
            line=""
        elif [ "$scenarioLine" != "$line" ]; then
            echo "Line $line is in the scenario at line $scenarioLine" >&2
            line="$scenarioLine"
        fi
    fi
    echo "$E2E_PACKAGE_IN_CONTAINER/${hostFile#"$MISE_PROJECT_ROOT/"}${line:+:$line}"
}

# Lets the user pick a feature file - prints its path relative to Tests/E2E/Features, fails when cancelled
e2e_choose_feature() {
    (cd "$E2E_FEATURES_DIR" && find . -name '*.feature' | sed 's|^\./||' | sort) | gum choose --header "Which feature should run?"
}

# The usage_* flags of the runner tasks (--trace, --timings, --no-prepare); arguments go to Behat
e2e_run() {
    [ "${usage_trace:-}" = "true" ] && export E2E_TRACE=always
    [ "${usage_timings:-}" = "true" ] && export E2E_TIMINGS=1
    if [ "${usage_no_prepare:-}" != "true" ]; then
        # the system under test runs in a Production context, which doesn't notice code changes - start from fresh caches
        e2e_compose exec -T neos ./flow flow:cache:flush || return 1
        e2e_compose exec -T neos ./flow flow:cache:warmup || return 1
    fi
    e2e_compose exec -T -e FLOW_CONTEXT=Testing/Behat -e E2E_TRACE -e E2E_TIMINGS neos ./bin/behat --strict -c "$E2E_PACKAGE_IN_CONTAINER/Tests/E2E/behat.yml.dist" "$@"
}
