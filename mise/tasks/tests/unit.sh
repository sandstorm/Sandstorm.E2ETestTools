#!/usr/bin/env bash

#MISE description="Run the package's unit tests"

source "$MISE_PROJECT_ROOT/mise/scripts/e2e-lib.sh"

e2e_phpunit UnitTests.xml Tests/Unit
