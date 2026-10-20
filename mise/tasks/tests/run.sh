#!/usr/bin/env bash

#MISE description="Run unit, functional and E2E tests - all three, also when one fails"

# all three run, so a run reports every failing kind - the task fails if one of them failed
code=0
mise run tests:unit || code=1
mise run tests:functional || code=1
mise run tests:e2e:runAll || code=1
exit $code
