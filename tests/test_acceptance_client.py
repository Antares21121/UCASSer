"""Acceptance retries only the core publishing timeout; other failures surface."""
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch, MagicMock
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parent))
from campus_support import Api


class PublishingTimeout(unittest.TestCase):
    def failure(self, code):
        return HTTPError('http://localhost/api/campus/records', 429, '', {},
                         io.BytesIO(json.dumps({'errors': [{'code': code}]}).encode()))

    @patch('campus_support.time.sleep')
    def test_core_timeout_waits_once_and_preserves_request(self, sleep):
        api = Api('http://localhost', 'test-token')
        response = MagicMock()
        response.__enter__.return_value = response
        response.status = 201
        response.read.return_value = b'{"data":{"id":1}}'
        api.opener.open = MagicMock(side_effect=[self.failure('too_many_requests'), response])
        self.assertEqual(api.call('/api/campus/records', 'POST', {'title': 'test'}, 201)['data']['id'], 1)
        sleep.assert_called_once_with(10.1)
        self.assertIs(api.opener.open.call_args_list[0].args[0], api.opener.open.call_args_list[1].args[0])

    @patch('campus_support.time.sleep')
    def test_account_rate_limit_is_not_retried(self, sleep):
        api = Api('http://localhost', 'test-token')
        api.opener.open = MagicMock(side_effect=self.failure('rate_limit'))
        with self.assertRaises(AssertionError):
            api.call('/api/campus/records', 'POST', {}, 201)
        sleep.assert_not_called()

    @patch('campus_support.time.sleep')
    def test_expected_rejection_remains_observable(self, sleep):
        api = Api('http://localhost', 'test-token')
        api.opener.open = MagicMock(side_effect=self.failure('too_many_requests'))
        self.assertEqual(api.call('/api/campus/records', 'POST', {}, 429)['errors'][0]['code'], 'too_many_requests')
        sleep.assert_not_called()
