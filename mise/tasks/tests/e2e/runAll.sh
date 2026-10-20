#!/usr/bin/env bash

#MISE description="Run the package's E2E suite (optionally only the scenarios with a tag)"
#USAGE flag "--tag <tag>" help="Only scenarios with this tag, e.g. playwright or mailpit"
#USAGE flag "--trace" help="Keep a Playwright trace of every scenario, not only of failed ones"
#USAGE flag "--timings" help="Write the duration of every step to Tests/SystemUnderTest/e2e-results/step-timings.jsonl"
#USAGE flag "--no-prepare" help="Skip flushing and warming up the caches of the system under test - faster re-runs without code changes"
#USAGE arg "[behat_args]..." var=#true help="Passed on to Behat after --, e.g. -- --name \"navigates the backend\""

source "$MISE_PROJECT_ROOT/mise/scripts/e2e-lib.sh"

eval "set -- ${usage_behat_args:-}"
[ -n "${usage_tag:-}" ] && set -- "--tags=${usage_tag#@}" "$@"
e2e_run "$@"
