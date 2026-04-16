# wlmonitor — test & utility targets.
#
#   make test              Run the full PHPUnit suite (Unit + Integration).
#   make test-unit         Unit tests only (no DB required).
#   make test-integration  Integration tests (needs local wlmonitor_dev + jardyx_auth).
#
# No test-db-setup target yet — wlmonitor's schema is not checkpointed as a
# single dump, so provisioning a fresh test DB requires manual coordination
# with ~/Git/auth/db and the migrations/ directory. CI currently runs Unit
# only; Integration runs locally against the dev DB.

PHPUNIT ?= ./vendor/bin/phpunit

.PHONY: test test-unit test-integration

test:
	$(PHPUNIT)

test-unit:
	$(PHPUNIT) --testsuite Unit

test-integration:
	$(PHPUNIT) --testsuite Integration
