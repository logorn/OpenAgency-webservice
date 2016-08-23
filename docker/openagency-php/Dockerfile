FROM docker-i.dbc.dk/dbc-apache-php
MAINTAINER iscrum, iscrum@dbc.dk

RUN apt-get update && apt-get install -qy --no-install-recommends gettext-base subversion && apt-get clean  && rm -rf /var/lib/apt/lists/*

RUN svn co https://svn.dbc.dk/repos/php/OpenLibrary/OpenAgency/trunk/ $APACHE_ROOT/

# Remove settings files as these will be overwritten later
RUN rm $APACHE_ROOT/openagency.ini_INSTALL
RUN rm $APACHE_ROOT/openagency.wsdl_INSTALL
RUN rm $APACHE_ROOT/robots.txt_INSTALL

# Adds settings files
ADD php-init.d/* $APACHE_ROOT/

RUN ln -s $APACHE_ROOT/server.php $APACHE_ROOT/index.php

ENV VERSION=2.28
ENV WSDL=openagency.wsdl
ENV DEFAULT_NAMESPACE_PREFIX=oa
ENV XMLNS_OA=http://oss.dbc.dk/ns/openagency
ENV XMLDIR=./xml
ENV LOGFILE=/tmp/openagency.log
ENV VERBOSE_LEVEL=WARNING+ERROR+FATAL+STAT+TIMER+TRACE+DEBUG+Z3950+OCI
ENV DUMP_TIMER=openAgency(%s)
ENV AGENCY_CREDENTIALS=some_user/some_pwd@some.server.dbc.dk
ENV Z3950_ADDRESS=z3950.dbc.dk:210/danbib
ENV ISO18626_ADDRESS=http://oss-services.dbc.dk/copa-rs/app/iso18626/
ENV ISO18626_PASSWORD=dbc_iso18626_password
ENV AAA_IP_RIGHTS_IP_LIST=172.16.0.0-172.31.255.255;193.111.162.0-193.111.162.255
ENV AAA_IP_RIGHTS_RESOURCE_LIST=500,550,551,552

#Replace variables in the settings files environment variables defined above
RUN $APACHE_ROOT/envsubst.sh
RUN rm $APACHE_ROOT/envsubst.sh

EXPOSE 80