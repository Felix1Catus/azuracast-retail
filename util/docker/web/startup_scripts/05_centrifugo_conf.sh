#!/bin/bash

ENABLE_REDIS=${ENABLE_REDIS:-true}
export ENABLE_REDIS

dockerize -template "/var/acacia/centrifugo/config.toml.tmpl:/var/acacia/centrifugo/config.toml"
