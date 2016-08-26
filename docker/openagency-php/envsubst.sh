#!/usr/bin/env bash

function die() {
  echo "ERROR:" "$@"
  exit 1
}

echo "-> Substituting enviroment variables in $APACHE_ROOT/openagency.ini"
envsubst  < $APACHE_ROOT/openagency.ini > $APACHE_ROOT/tmp.txt
cat $APACHE_ROOT/tmp.txt > $APACHE_ROOT/openagency.ini

echo "-> Substituting enviroment variables in $APACHE_ROOT/openagency.wsdl"
envsubst  < $APACHE_ROOT/openagency.wsdl > $APACHE_ROOT/tmp.txt
cat $APACHE_ROOT/tmp.txt > $APACHE_ROOT/openagency.wsdl

echo "-> Substituting enviroment variables in $APACHE_ROOT/robots.txt"
envsubst  < $APACHE_ROOT/robots.txt > $APACHE_ROOT/tmp.txt
cat $APACHE_ROOT/tmp.txt > $APACHE_ROOT/robots.txt

echo "-> Removing tmp file"
rm $APACHE_ROOT/tmp.txt || die "No tmp file to remove"