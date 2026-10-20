#!/usr/bin/env bash

#MISE description="Run one feature file or scenario - without a path, choose the feature from a list"
#USAGE arg "[path]" help="Feature file relative to Tests/E2E/Features (or the current directory), with :line for one scenario, e.g. Browser/Navigation.feature:21"
#USAGE complete "path" run="cd Tests/E2E/Features && find . -name '*.feature' | sed 's|^./||' | sort"
#USAGE flag "--trace" help="Keep a Playwright trace of every scenario, not only of failed ones"
#USAGE flag "--timings" help="Write the duration of every step to Tests/SystemUnderTest/e2e-results/step-timings.jsonl"
#USAGE flag "--no-prepare" help="Skip flushing and warming up the caches of the system under test - faster re-runs without code changes"
#USAGE arg "[behat_args]..." var=#true help="Passed on to Behat after --, e.g. -- --stop-on-failure"

source "$MISE_PROJECT_ROOT/mise/scripts/e2e-lib.sh"

path="${usage_path:-}"
if [ -z "$path" ]; then
    # cancelled with Esc / Ctrl+C -> no run
    path=$(e2e_choose_feature) || exit 1
fi
feature=$(e2e_feature_path "$path") || exit 1

eval "set -- ${usage_behat_args:-}"
e2e_run "$feature" "$@"
