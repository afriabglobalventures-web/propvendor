// This file is for application-wide constants.
import {
  HouseIcon,
  CarIcon,
  BriefcaseIcon,
  ToolsIcon
} from './components';
import { Category, Listing } from './types';

export const CATEGORIES: Category[] = [
  { id: 'properties', name: 'Properties', icon: HouseIcon },
  { id: 'cars', name: 'Cars', icon: CarIcon },
  { id: 'rentals', name: 'Rentals', icon: BriefcaseIcon },
  { id: 'services', name: 'Services', icon: ToolsIcon },
];

export const LISTINGS: Listing[] = [
  {
    id: '1',
    title: 'Luxury Villa in Lekki',
    price: 150000000,
    location: 'Lekki, Lagos',
    image: 'https://images.unsplash.com/photo-1580587771525-78b9dba3b914?q=80&w=400',
    category: 'Properties',
  },
  {
    id: '2',
    title: '2021 Toyota Camry',
    price: 18000000,
    location: 'Ikeja, Lagos',
    image: 'https://images.unsplash.com/photo-1617469723233-a02241b723b7?q=80&w=400',
    category: 'Cars',
  },
  {
    id: '3',
    title: 'Co-working Space',
    price: 50000,
    location: 'Yaba, Lagos',
    image: 'https://images.unsplash.com/photo-1554469384-e58fac16e23a?q=80&w=400',
    category: 'Rentals',
  },
  {
    id: '4',
    title: 'Modern 3-Bedroom Flat',
    price: 75000000,
    location: 'Abuja, FCT',
    image: 'https://images.unsplash.com/photo-1570129477492-45c003edd2be?q=80&w=400',
    category: 'Properties',
  },
    {
    id: '5',
    title: 'Clean Honda Accord 2019',
    price: 12500000,
    location: 'Port Harcourt, Rivers',
    image: 'https://images.unsplash.com/photo-1555315131-f2a8c2c1a1eb?q=80&w=400',
    category: 'Cars',
  },
  {
    id: '6',
    title: 'Event Hall for 500 Guests',
    price: 250000,
    location: 'Victoria Island, Lagos',
    image: 'https://images.unsplash.com/photo-1532566738445-424b8a245997?q=80&w=400',
    category: 'Rentals',
  },
];

export const DEALS: Listing[] = [
   {
    id: '7',
    title: 'Beachfront Getaway House',
    price: 85000,
    location: 'Elegushi, Lagos',
    image: 'https://images.unsplash.com/photo-1594488518063-4a173b259743?q=80&w=400',
    category: 'Rentals',
  },
  {
    id: '8',
    title: 'Weekend Car Rental Deal',
    price: 25000,
    location: 'Surulere, Lagos',
    image: 'https://images.unsplash.com/photo-1599602052953-2c160f4b3a1a?q=80&w=400',
    category: 'Cars',
  },
];
