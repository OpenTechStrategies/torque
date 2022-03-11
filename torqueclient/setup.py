#!/usr/bin/env python

from distutils.core import setup

main_ns = {}
with open('torqueclient/version.py') as ver_file:
    exec(ver_file.read(), main_ns)

with open("README.md", "r", encoding="utf-8") as readme:
    long_description = readme.read()

setup(
    name="torqueclient",
    version="0.2.1",
    #version=main_ns['__version__'],
    description="Python client for mediawiki/torque",
    long_description=long_description,
    long_description_content_type="text/markdown",
    author="Open Tech Strategies, LLC",
    author_email="frankduncan@opentechstrategies.com", # For now, this works
    url="https://github.com/OpenTechStrategies/torque",
    classifiers=["Programming Language :: Python :: 3",
        "License :: OSI Approved :: GNU Affero General Public License v3",
        "Operating System :: OS Independent",
    ],
    packages=["torqueclient"],
    install_requires=["mwclient", "python-dateutil"],
    package_dir={"":  "."},
    python_requres=">=3.6",
)
