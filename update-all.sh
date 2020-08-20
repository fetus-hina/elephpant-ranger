#!/bin/bash

set -eu

SCRIPT_DIR=$(cd $(dirname $0);pwd)
/bin/flock -x -n $SCRIPT_DIR/update-all.lock scl enable php74 -- php $SCRIPT_DIR/update-all.php

