#!/bin/bash

set -eu

pushd $(cd $(dirname $0) && pwd)/../opt

if [ ! -e pypy ]; then
  wget -O pypy-download.tar.gz \
    https://bitbucket.org/pypy/pypy/get/default.tar.gz 

  mkdir pypy
  tar -xvf pypy-download.tar.gz -C pypy --strip-components 1
  rm -f pypy-download.tar.gz
fi

popd
