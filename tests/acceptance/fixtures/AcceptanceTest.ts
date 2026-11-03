import { test as ShopwareTestSuite, mergeTests } from '@shopware-ag/acceptance-test-suite';
import type { FixtureTypes as BaseTypes } from '@shopware-ag/acceptance-test-suite';
import { test as adminSession } from './AdminSession';
import { test as ucpConsole } from './UcpConsole';
import { test as ucpTestData } from './UcpTestData';
import { test as ucpAgentProfileHost } from './UcpAgentProfileHost';
import { test as ucpAclUsers } from './UcpAclUsers';
import { test as ucpAgent } from './UcpAgent';
import type { UcpConsoleTypes } from './UcpConsole';
import type { UcpTestDataFixtureTypes } from './UcpTestData';
import type { UcpAgentProfileHostTypes } from './UcpAgentProfileHost';
import type { UcpAclUsersTypes } from './UcpAclUsers';
import type { UcpAgentTypes } from './UcpAgent';

export * from '@shopware-ag/acceptance-test-suite';

export type FixtureTypes = Omit<BaseTypes, 'TestDataService'>
  & UcpTestDataFixtureTypes
  & UcpConsoleTypes
  & UcpAgentProfileHostTypes
  & UcpAclUsersTypes
  & UcpAgentTypes;

export const test = mergeTests(
    ShopwareTestSuite,
    adminSession,
    ucpConsole,
    ucpTestData,
    ucpAgentProfileHost,
    ucpAclUsers,
    ucpAgent,
);
