#!/bin/bash

DIR=$(cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd)
cd $DIR


vendor/bin/phpunit --coverage-html=coverage

