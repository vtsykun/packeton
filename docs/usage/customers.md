# Customer Users

You can create customers user and manage their packages access.
The customer users have may have two users role:

- `ROLE_USER` - minimal access level, these users only can read metadata only for selected packages
- `ROLE_FULL_CUSTOMER` - Can read all packages metadata without groups ACL restriction.

For `ROLE_USER` you will be able to limit packages access by release date too. 

To grant access to your packages, need to create ACL group in the first.

[![Groups](../img/groups.png)](../img/groups.png)

### Create Customer User.

After creating an ACL group, you may to create a user and grant access to more that one groups.
If selected more than one group, then all groups permission will be union together. 

[![Users](../img/users.png)](../img/users.png)
