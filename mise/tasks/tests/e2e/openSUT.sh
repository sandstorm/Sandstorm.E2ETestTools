#!/usr/bin/env bash

#MISE description="Open the system under test in the browser (127.0.0.1:19090)"
#USAGE flag "--backend" help="Open the Neos backend instead of the site"

if [ "${usage_backend:-}" = "true" ]; then
    open http://127.0.0.1:19090/neos
else
    open http://127.0.0.1:19090
fi
