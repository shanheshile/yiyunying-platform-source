package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class GroupManagementPolicyTest {
    @Test public void ordinaryMemberOnlyInvitesWhenRoomPolicyAllowsIt() {
        assertFalse(GroupManagementPolicy.canInvite("member", false));
        assertTrue(GroupManagementPolicy.canInvite("member", true));
    }

    @Test public void groupAdminOnlyModeratesOrdinaryMembers() {
        assertTrue(GroupManagementPolicy.canModerate("admin", "member", false));
        assertFalse(GroupManagementPolicy.canModerate("admin", "admin", false));
        assertFalse(GroupManagementPolicy.canModerate("admin", "owner", false));
        assertFalse(GroupManagementPolicy.canChangeRole("admin", "member", false));
    }

    @Test public void ownerCanAssignRolesAndModerateNonOwnerMembers() {
        assertTrue(GroupManagementPolicy.canChangeRole("owner", "admin", false));
        assertTrue(GroupManagementPolicy.canModerate("owner", "admin", false));
        assertFalse(GroupManagementPolicy.canChangeRole("owner", "owner", false));
        assertFalse(GroupManagementPolicy.canModerate("owner", "member", true));
    }

    @Test public void systemAdministratorGetsManagementWithoutImpersonatingAUser() {
        assertTrue(GroupManagementPolicy.isManager(GroupManagementPolicy.SYSTEM_ADMIN));
        assertTrue(GroupManagementPolicy.canChangeRole(
            GroupManagementPolicy.SYSTEM_ADMIN, "member", false));
        assertTrue(GroupManagementPolicy.canModerate(
            GroupManagementPolicy.SYSTEM_ADMIN, "admin", false));
        assertFalse(GroupManagementPolicy.canModerate(
            GroupManagementPolicy.SYSTEM_ADMIN, "owner", false));
    }
}
