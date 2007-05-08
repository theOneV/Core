#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_phorum.h"

static function_entry phorum_functions[] = {
    PHP_FE(phorum_ext_treesort, NULL)
    {NULL, NULL, NULL}
};

zend_module_entry phorum_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
    STANDARD_MODULE_HEADER,
#endif
    PHP_PHORUM_EXTNAME,
    phorum_functions,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
#if ZEND_MODULE_API_NO >= 20010901
    PHP_PHORUM_VERSION,
#endif
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PHORUM
ZEND_GET_MODULE(phorum)
#endif

/**
 * This is the C implementation of the Phorum phorum_treesort()
 * functionality.
 *
 * @param array
 *    The array to sort. The array elements are arrays, in which at
 *    least some id and some parent id field must be available.
 *    These elements are the nodes which have to be ordered into a tree.
 *
 * @param string
 *    The name of the id field in the array elements.
 *
 * @param string
 *    The name of the parent id field in the array elements.
 *
 * @param int
 *    The indention factor. Each element array will get a new field
 *    named "indent_cnt". This field will contain the indent level of
 *    the element in the tree, multiplied with the indention factor 
 *    value. If this parameter is 0 (zero, the default), then no
 *    "indent_cnt" field is added at all.
 *
 * @param string
 *    The name of an element field, which should be processed to
 *    cut long words into smaller words. This can be used to make
 *    very large words wrap in the browser by inserting spaces at
 *    appropriate places.
 *
 * @param int
 *    The maximum length for a word in the cut field.
 *
 * @param int
 *    The minimum length for a word in the cut field.
 *
 * @param int
 *    A factor to make the maximum length lower for elements that
 *    are at higher indent level. The maximum allowed word length
 *    is computed as (maximum length - factor*indent). If this 
 *    gets below the minimum length, the minimum length will be
 *    used.
 */
PHP_FUNCTION(phorum_ext_treesort)
{
    zval            *nodes;
    zval           **data;
    HashTable       *nodes_hash;
    HashPosition     node_pointer;
    long             treepos;
    phorum_tree      tree;
    phorum_treenode *cur_node;
    phorum_treenode *new_node;
    char            *fld_id;
    int              fld_id_len;
    char            *fld_parent_id;
    int              fld_parent_id_len;
    int              success = 1;
    long             indent_factor = 0;
    char            *fld_cut = NULL;
    int              fld_cut_len = 0;
    long             cut_max = 60;
    long             cut_min = 20;
    long             cut_indent_factor = 2;

    bzero(&tree, sizeof(phorum_tree));

    /* Create a top level node to which all the parent id = 0 nodes can be connected. */
    new_node = (phorum_treenode *)emalloc(sizeof(phorum_treenode));
    if (! new_node) {success = 0; goto error; }
    bzero(new_node, sizeof(phorum_treenode));
    new_node->id = 0;
    new_node->parent_id = 0;
    new_node->seen = 1;
    new_node->indent_lvl = -1;

    tree.node_first = new_node;
    tree.node_last = new_node;

    /* ================================================================== */
    /* Walk over the array and describe the tree as a linked lists system */
    /* ================================================================== */

    /* Retrieve our function call parameters. */
    if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ass|lslll", &nodes, &fld_id, &fld_id_len, &fld_parent_id, &fld_parent_id_len, &indent_factor, &fld_cut, &fld_cut_len, &cut_min, &cut_max, &cut_indent_factor) == FAILURE) {
        success = 0;
        goto error;
    }
    nodes_hash = Z_ARRVAL_P(nodes);

    /* Walk over the array. */
    for(zend_hash_internal_pointer_reset_ex(nodes_hash, &node_pointer);
        zend_hash_get_current_data_ex(nodes_hash, (void**) &data, &node_pointer) == SUCCESS;
        zend_hash_move_forward_ex(nodes_hash, &node_pointer)) {

        zval     **find;
        long       parent_id = 0;
        long       message_id = 0;
        int        found = 0;
        zval      *node = *data;
        HashTable *node_hash;

        /* Get the hash table for the current node (nodes are arrays too). */
        if (Z_TYPE_P(*data) != IS_ARRAY) {
            zend_error(E_WARNING, "Phorum sort threads: one of the nodes contains a non-array element");
            success = 0;
            goto error;
        }
        node_hash = Z_ARRVAL_P(node);

        if (zend_hash_find(node_hash, fld_parent_id, fld_parent_id_len+1, (void **)&find) == SUCCESS) {
            convert_to_long(*find);
            parent_id = Z_LVAL_P(*find);
            found ++;
        }

        if (zend_hash_find(node_hash, fld_id, fld_id_len+1, (void **)&find) == SUCCESS) {
            convert_to_long(*find);
            message_id = Z_LVAL_P(*find);
            found ++;
        }

        if (found != 2) {
            zend_error(
              E_WARNING,
              "Phorum sort threads: one of the nodes does not contain both fields \"%s\" and \"%s\"",
              fld_id, fld_parent_id
            );
            success = 0;
            goto error;
        }

        /* Create a new phorum tree node. */
        new_node = (phorum_treenode *)emalloc(sizeof(phorum_treenode));
        if (! new_node) zend_error(E_ERROR, "Out of memory");
        bzero(new_node, sizeof(phorum_treenode));
        new_node->id = message_id;
        new_node->parent_id = parent_id;
        new_node->hp = node_pointer;

        /* Find the parent node for the new node. */
        for (cur_node = tree.node_first; cur_node; cur_node = cur_node->next) {
            if (cur_node->id == new_node->parent_id) break;
        }
        if (! cur_node) {
            zend_error(
                E_WARNING,
                "Phorum sort threads: No parent found for tree node (id %ld)\n", new_node->id
            );
            success = 0;
            goto error;
        }

        new_node->indent_lvl = cur_node->indent_lvl + 1;

        /* Append the node to the linear list. */
        tree.node_last->next = new_node; 
        new_node->prev = tree.node_last;
        tree.node_last = new_node;

        /* Place the node in the tree. */
        new_node->parent = cur_node;
        if (cur_node->child_first == NULL) {
            cur_node->child_first = cur_node->child_last = new_node;
        }
        else {
            phorum_treenode *n = cur_node->child_last;
            n->sibling = new_node;
            cur_node->child_last = new_node;
        }

        /* Set the indent level in the node. */
        if (indent_factor != 0) {
            if (add_assoc_long(*data, "indent_cnt", new_node->indent_lvl * indent_factor) == FAILURE) {
                zend_error(
                    E_WARNING,
                    "Phorum sort threads: Failed to set the indent_cnt field for a node"
                );
                success = 0;
                goto error;
            }
        }

        /* Cut long words in a field if requested. */
        if (fld_cut != NULL && 
            zend_hash_find(
                node_hash,
                fld_cut, fld_cut_len+1,
                (void **)&find) == SUCCESS) {

            int cutlen;

            /* Determine the word cutting length. */
            cutlen = cut_max - new_node->indent_lvl * cut_indent_factor;
            if (cutlen < cut_min) cutlen = cut_min;

            convert_to_string(*find);

            /* We can skip the wrap check if the subject is shorter than the 
             * wrap length already. */
            if (Z_STRLEN_PP(find) > cutlen)
            {
                int   len = Z_STRLEN_PP(find);
                char *str = Z_STRVAL_PP(find);
                int   pos;
                int   lastpos = 0;
                int   cutcount = 0;

                /* First pass: see how many cuts would have to be made. */
                for (pos = 0; pos < len; pos++)
                {
                    /* If we find a space, then start counting 
                     * a new word's length. */
                    if (str[pos]==' ' || str[pos]=='\t' || str[pos]=='\n') {
                        pos++; lastpos = pos; continue;
                    }

                    /* Too long word found? */
                    if ((pos - lastpos) >= cutlen) {
                        cutcount ++; lastpos = pos;
                    }
                }

                /* Second pass: create a new subject if cuts are needed. */
                if (cutcount > 0)
                {
                    int   newlen = len + cutcount;
                    char *newstr = emalloc(newlen + 1);
                    int   newpos = 0;
                    zval *strzval;

                    if (! newstr) {success = 0; goto error; }

                    /* First pass: see how many cuts would have to be made. */
                    lastpos = 0;
                    for (pos = 0; pos < len; pos++)
                    {
                        /* If we find a space, then start counting a new word's length. */
                        if (str[pos] == ' ' || str[pos] == '\t' || str[pos] == '\n') {
                            newstr[newpos++] = str[pos++];
                            lastpos = pos;
                        }

                        /* Too long word found? Then insert a space.*/
                        else if ((pos - lastpos) >= cutlen) {
                            newstr[newpos++] = ' ';    
                            lastpos = pos;
                        }

                        /* Copy the next character to the new string. 
                         * (pos < len is for the pos++ in the space condition above) */
                        if (pos < len) newstr[newpos++] = str[pos];
                    }

                    newstr[newpos] = '\0';

                    /* Put the new subject in the array. */
                    MAKE_STD_ZVAL(strzval);
                    Z_TYPE_P(strzval) = IS_STRING;
                    Z_STRLEN_P(strzval) = newlen;
                    Z_STRVAL_P(strzval) = newstr;
                    zend_hash_update(
                        node_hash, 
                        fld_cut, fld_cut_len+1,
                        (void *)&strzval, sizeof(zval *),
                        NULL
                    );
                }
            }
        }
    }

    /* ================================================== */
    /* Reorder the array buckets to match the tree order. */
    /* ================================================== */

    nodes_hash->pListHead = NULL;
    nodes_hash->pListTail = NULL;
    nodes_hash->pInternalPointer = NULL;

    cur_node = tree.node_first;
    while (cur_node != NULL)
    {
        phorum_treenode *next = NULL;
        phorum_treenode *free = NULL;

        /* If we did not process this node before, then add it 
         * to the resulting sorted tree hashtable.
         */
        if (cur_node->seen == 0)
        {
            /* The first node. */
            if (nodes_hash->pListHead == NULL) {
                nodes_hash->pListHead = cur_node->hp;
                nodes_hash->pListTail = cur_node->hp;
                nodes_hash->pInternalPointer = cur_node->hp;
                cur_node->hp->pListLast = NULL;
                cur_node->hp->pListNext = NULL;
            /* Follow up nodes. */
            } else {
                nodes_hash->pListTail->pListNext = cur_node->hp;
                cur_node->hp->pListLast = nodes_hash->pListTail;
                cur_node->hp->pListNext = NULL;
                nodes_hash->pListTail = cur_node->hp;
            }

            cur_node->seen = 1;
        }

        /* Some tree traversal logic (draw it out on paper if it
         * does not make sense ;-):
         *
         * Go to the first child node of the current node if there is one. */
        if (cur_node->child_first != NULL) {
            next = cur_node->child_first;
            /* Block the child road for when we return here. */
            cur_node->child_first = NULL;
        /* If there's no first child node, then go to our sibling. */
        } else if (cur_node->sibling != NULL) {
            next = cur_node->sibling;
        /* If there's no first child or sibling, then return to our parent. */
        } else if (cur_node->parent != NULL) {
            next = cur_node->parent;
        }

        cur_node = next;
    }

    /* ======================= */
    /* Cleanup after ourselves */ 
    /* ======================= */

    error:

    cur_node = tree.node_first;
    while (cur_node != NULL) {
        phorum_treenode *next = cur_node->next;
        efree(cur_node);
        cur_node = next;
    }

    if (success == 1) {
        RETURN_TRUE;
    } else {
        RETURN_FALSE;
    }
}

