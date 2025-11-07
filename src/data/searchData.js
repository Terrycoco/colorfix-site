// src/test/searchData.js

export const searchItems = [
  {  type: 'search',
    id: 'family',
    label: 'By Color Family',
    description: 'Search by hue group like reds, blues, neutrals',
    children: [
      {
        type: 'category',
        id: 'reds',
        label: 'Reds',
        color: '#b91c1c',
        queryId: 101
      },
      {
        type: 'category',
        id: 'blues',
        label: 'Blues',
        color: '#1e3a8a',
        queryId: 102
      },
      {
        type: 'category',
        id: 'greens',
        label: 'Greens',
        color: '#166534',
        queryId: 103
      },
      {
        type: 'category',
        id: 'neutrals',
        label: 'Neutrals',
        color: '#6b7280',
        queryId: 104
      }
    ]
  },
  {
     type: 'search',
    id: 'brand',
    label: 'By Brand',
    description: 'Search by brand like Benjamin Moore, Behr, etc.',
    children: [
      {
        type: 'category',
        id: 'bm',
        label: 'Benjamin Moore',
        queryId: 201
      },
      {
           type: 'category',
        id: 'behr',
        label: 'Behr',
        queryId: 202
      },
      {
           type: 'category',
        id: 'sw',
        label: 'Sherwin-Williams',
        queryId: 203
      }
    ]
  }
];
