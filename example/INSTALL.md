These instructions outline how to install the example data on a running
mediawiki and torque system.  Please first follow
[the instructions](../INSTALL.md) regarding installing mediawiki,
and also the ones for
[installing and running torque](../torquedata/README.md#installation-and-startup).

# Installing the plugin and mediawiki settings via ansible

There is an ansible playbook to set up the torque part of the mediawiki system.

## Pre-requisites

You'll first need to install ansible-playbook

```shell
$ sudo apt install ansible-playbook
```

## Configuring

You need to configure the group\_vars/all file:

```shell
$ cp group_vars/all{.tmpl,}
$ $EDITOR group_vars/all
```

## Installing

Then run the playbook:

```shell
# You may need the -b option in order to sudo to run the commands
$ ansible-playbook example.yml
```

# Running an ETL pipeline and uploading the data

There are two parts to the uploading of a spreadsheet.  The first is an
ETL pipeline that converts the example spreadsheets to a single spreadsheet
to upload.  This is based on the work done in the
[torque-sites repository](https://github.com/OpenTechStrategies/torque-sites),
and while more complicated than the bare minimum necessary to demonstrate the
system, it gives a more complete view of the data.

The second is the actual uploading of all the data, and creation of mediawiki
pages.

In order to understand the example, a thorough reading of the code is necessary.

## Prerequisites

You'll need to have python3 installed

```shell
$ sudo apt install python3
```

## Configuring

Then copy and then edit the configuration:

```shell
$ cp config.py.tmpl config.py
$ $EDITOR config.py
```

## Installing

Finally, run the deployment script.

```shell
$ ./deploy
```
